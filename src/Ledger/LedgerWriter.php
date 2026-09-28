<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Models;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The only code that moves money. Each call changes a wallet's balance and
 * appends the matching ledger entry in one database transaction.
 *
 * The balance changes through a single guarded UPDATE: for a debit the
 * WHERE clause itself checks the minimum balance, so two concurrent debits
 * can never both pass the check (the row lock taken by the first UPDATE
 * makes the second re-evaluate against the committed balance). The wallet
 * instance's in-memory balance is never trusted; it is refreshed from the
 * database after the write.
 *
 * Writes go through the connection's plain query builder: the Eloquent
 * builders of both models refuse them by design.
 *
 * In-memory state and outer transactions: after a successful write the
 * wallet instance holds the new balance. When the write ran inside an outer
 * transaction, a rollback callback is registered on the innermost enclosing
 * transaction that puts the previous balance and updated_at back on the
 * instance if that transaction rolls back. Laravel only runs rollback
 * callbacks of the transaction that is rolled back (and its still-open
 * parents), so if the enclosing transaction is a savepoint that commits and
 * a transaction further out rolls back later, the instance keeps the new
 * value; refresh() it in that case.
 *
 * Idempotency: when a key is given, a ledger entry already recorded on the
 * wallet under that key is returned instead of writing again, provided it
 * describes the same operation (same Fingerprint); otherwise
 * IdempotencyConflict is thrown. A replay changes nothing: no balance
 * change, no new row, no TransactionRecorded event, and the wallet
 * instance is left as it is (refresh() it to see the current balance). The
 * key is only stored with a successful write, so a failed attempt (e.g.
 * InsufficientFunds) can be retried with the same key. Two concurrent
 * requests with the same key both pass the first lookup; the unique index
 * on (wallet_id, idempotency_key) makes the second insert fail, which rolls
 * back its transaction (or savepoint, inside an outer transaction) including
 * the balance change, and the winner's entry is then looked up again.
 *
 * @internal
 */
final class LedgerWriter
{
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    /**
     * Applies $delta (positive credit, negative debit) to the wallet and
     * records the ledger entry.
     *
     * @throws InsufficientFunds when a debit would take the balance below the minimum balance
     * @throws BalanceOverflow when a credit would overflow the balance
     * @throws WalletException when the wallet is not saved or no longer exists
     * @throws InvalidTransaction when the idempotency key is invalid
     * @throws IdempotencyConflict when the idempotency key was already used on the wallet for a different operation
     */
    public function record(Wallet $wallet, int $delta, Details $details, ?string $idempotencyKey = null): WalletTransaction
    {
        if ($delta === 0) {
            throw InvalidAmount::zero();
        }

        if (! $wallet->exists || $wallet->getKey() === null) {
            throw new WalletException('Cannot record a transaction on an unsaved wallet.');
        }

        $idempotency = null;

        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);

            $idempotency = ['key' => $idempotencyKey, 'hash' => Fingerprint::for($delta, $details)];

            if ($existing = $this->findByKey($wallet, $idempotencyKey, lock: false)) {
                return $this->replay($wallet, $existing, $idempotency, $details);
            }
        }

        $connection = $wallet->getConnection();
        $timestampColumn = $wallet->usesTimestamps() ? $wallet->getUpdatedAtColumn() : null;
        $now = $wallet->freshTimestamp();

        $previousBalance = (int) $wallet->getAttribute('balance');
        $previousTimestamp = $timestampColumn === null ? null : ($wallet->getAttributes()[$timestampColumn] ?? null);

        try {
            [$transaction, $balance] = $connection->transaction(
                fn (Connection $connection): array => $this->write($connection, $wallet, $delta, $details, $idempotency, $timestampColumn, $now),
            );
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first. Our
            // transaction (or savepoint) has been rolled back, balance change
            // included. The locking read sees the winner's committed row even
            // inside an outer REPEATABLE READ transaction, where a plain read
            // would still use the old snapshot.
            if ($idempotency === null) {
                throw $e;
            }

            $existing = $this->findByKey($wallet, $idempotency['key'], lock: true) ?? throw $e;

            return $this->replay($wallet, $existing, $idempotency, $details, $e);
        }

        $this->syncWallet($wallet, $balance, $timestampColumn, $timestampColumn === null ? null : $wallet->fromDateTime($now));

        $this->restoreOnRollback($connection, $wallet, $delta, $previousBalance, $timestampColumn, $previousTimestamp, $now);

        $this->dispatch(new TransactionRecorded($transaction));

        return $transaction;
    }

    /**
     * @param  array{key: string, hash: string}|null  $idempotency
     * @return array{WalletTransaction, int}
     */
    private function write(
        Connection $connection,
        Wallet $wallet,
        int $delta,
        Details $details,
        ?array $idempotency,
        ?string $timestampColumn,
        DateTimeInterface $now,
    ): array {
        $extra = $timestampColumn === null ? [] : [$timestampColumn => $wallet->fromDateTime($now)];
        $query = $this->walletRow($connection, $wallet);
        $grammar = $query->getGrammar();

        if ($delta < 0) {
            // The floor is checked by the UPDATE itself, against the row as
            // it is in the database.
            $affected = $query
                ->whereRaw($grammar->wrap('balance').' + ? >= '.$grammar->wrap('min_balance'), [$delta])
                ->decrement('balance', -$delta, $extra);
        } else {
            // No floor check: a wallet already below its floor (e.g. after
            // its credit line was reduced) must still accept money. The
            // overflow guard matters on SQLite, which would otherwise turn
            // the value into a float silently.
            $affected = $query
                ->where('balance', '<=', PHP_INT_MAX - $delta)
                ->increment('balance', $delta, $extra);
        }

        if ($affected === 0) {
            throw $this->refusal($connection, $wallet, $delta);
        }

        // The UPDATE above holds the row lock until commit, so this reads
        // exactly the balance our change produced.
        $balance = (int) $this->walletRow($connection, $wallet)->value('balance');

        return [$this->insertTransaction($connection, $wallet, $delta, $balance, $details, $idempotency, $now), $balance];
    }

    /**
     * @param  array{key: string, hash: string}|null  $idempotency
     */
    private function insertTransaction(
        Connection $connection,
        Wallet $wallet,
        int $delta,
        int $balance,
        Details $details,
        ?array $idempotency,
        DateTimeInterface $now,
    ): WalletTransaction {
        $class = Models::transaction();
        $transaction = new $class;
        $transaction->setConnection($wallet->getConnectionName());

        // forceFill() runs the values through the model's casts (meta to
        // JSON, created_at to the model's date format).
        $transaction->forceFill([
            'wallet_id' => $wallet->getKey(),
            'type' => $details->type,
            'amount' => $delta,
            'balance_after' => $balance,
            'reference_type' => $details->reference?->getMorphClass(),
            'reference_id' => $details->reference?->getKey(),
            'performed_by_type' => $details->performedBy?->getMorphClass(),
            'performed_by_id' => $details->performedBy?->getKey(),
            'description' => $details->description,
            'meta' => $details->meta === [] ? null : $details->meta,
            'idempotency_key' => $idempotency['key'] ?? null,
            'idempotency_hash' => $idempotency['hash'] ?? null,
            'created_at' => $now,
        ]);

        $keyName = $transaction->getKeyName();

        $id = $connection->table($transaction->getTable())->insertGetId($transaction->getAttributes(), $keyName);

        $transaction->setAttribute($keyName, $id);
        $transaction->exists = true;
        $transaction->wasRecentlyCreated = true;
        $transaction->syncOriginal();

        $transaction->setRelation('wallet', $wallet);
        $transaction->setRelation('reference', $details->reference);
        $transaction->setRelation('performedBy', $details->performedBy);

        return $transaction;
    }

    /**
     * Stored exactly as given: no trimming or normalisation.
     *
     * @throws InvalidTransaction
     */
    private static function assertIdempotencyKey(string $key): void
    {
        if (trim($key) === '') {
            throw InvalidTransaction::emptyIdempotencyKey();
        }

        if (! mb_check_encoding($key, 'UTF-8')) {
            throw InvalidTransaction::idempotencyKeyNotUtf8();
        }

        if (($length = mb_strlen($key, 'UTF-8')) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw InvalidTransaction::idempotencyKeyTooLong($length, self::MAX_IDEMPOTENCY_KEY_LENGTH);
        }
    }

    /**
     * The wallet's entry recorded under the key, read through Eloquent on
     * the wallet's connection. With $lock the read is a locking (shared)
     * read, which sees the latest committed row whatever the isolation
     * level of an enclosing transaction.
     */
    private function findByKey(Wallet $wallet, string $key, bool $lock): ?WalletTransaction
    {
        $class = Models::transaction();

        $query = (new $class)->setConnection($wallet->getConnectionName())->newQuery()
            ->where('wallet_id', $wallet->getKey())
            ->where('idempotency_key', $key);

        if ($lock) {
            $query->sharedLock();
        }

        return $query->first();
    }

    /**
     * Returns the entry already recorded under the key when it describes the
     * same operation. Nothing is written and no event is dispatched; the
     * wallet instance is not touched.
     *
     * @param  array{key: string, hash: string}  $idempotency
     *
     * @throws IdempotencyConflict
     */
    private function replay(
        Wallet $wallet,
        WalletTransaction $existing,
        array $idempotency,
        Details $details,
        ?UniqueConstraintViolationException $previous = null,
    ): WalletTransaction {
        $stored = $existing->getRawOriginal('idempotency_hash');

        if (! is_string($stored) || ! hash_equals($stored, $idempotency['hash'])) {
            throw IdempotencyConflict::for($wallet, $idempotency['key'], $existing, $previous);
        }

        // Equal fingerprints mean the same reference and actor, so the
        // request's models can stand in for the relations.
        $existing->setRelation('wallet', $wallet);
        $existing->setRelation('reference', $details->reference);
        $existing->setRelation('performedBy', $details->performedBy);

        return $existing;
    }

    /**
     * Explains why the guarded UPDATE matched no row.
     */
    private function refusal(Connection $connection, Wallet $wallet, int $delta): WalletException
    {
        $row = $this->walletRow($connection, $wallet)->first(['balance', 'min_balance']);

        if ($row === null) {
            return new WalletException(sprintf('Wallet [%s] no longer exists.', (string) $wallet->getKey()));
        }

        $balance = (int) $row->balance;

        return $delta < 0
            ? InsufficientFunds::for($wallet, -$delta, $balance, (int) $row->min_balance)
            : BalanceOverflow::for($wallet, $delta, $balance);
    }

    private function walletRow(Connection $connection, Wallet $wallet): QueryBuilder
    {
        return $connection->table($wallet->getTable())->where($wallet->getKeyName(), $wallet->getKey());
    }

    /**
     * Puts the committed values on the instance and marks them clean, so a
     * later save() of harmless columns does not trip over a dirty balance.
     */
    private function syncWallet(Wallet $wallet, int $balance, ?string $timestampColumn, mixed $timestamp): void
    {
        $wallet->setAttribute('balance', $balance);

        if ($timestampColumn !== null) {
            $wallet->setAttribute($timestampColumn, $timestamp);
        }

        $wallet->syncOriginalAttributes(array_filter(['balance', $timestampColumn]));
    }

    /**
     * When an outer transaction is open, undo the in-memory change if it
     * rolls back. The balance is restored by subtracting $delta rather than
     * by writing back a snapshot, so several writes on the same instance
     * inside one transaction unwind correctly whatever order the callbacks
     * run in. updated_at goes back to its old value unless something newer
     * has been written since.
     */
    private function restoreOnRollback(
        Connection $connection,
        Wallet $wallet,
        int $delta,
        int $previousBalance,
        ?string $timestampColumn,
        mixed $previousTimestamp,
        DateTimeInterface $now,
    ): void {
        if ($connection->transactionLevel() === 0) {
            return;
        }

        try {
            $connection->afterRollBack(function () use ($wallet, $delta, $previousBalance, $timestampColumn, $previousTimestamp, $now): void {
                $balance = $wallet->getAttribute('balance');

                $this->syncWallet(
                    $wallet,
                    is_int($balance) ? $balance - $delta : $previousBalance,
                    null,
                    null,
                );

                if ($timestampColumn !== null) {
                    $current = $wallet->getAttribute($timestampColumn);

                    if (! $current instanceof DateTimeInterface || $current >= $now) {
                        $wallet->setAttribute($timestampColumn, $previousTimestamp);
                        $wallet->syncOriginalAttributes($timestampColumn);
                    }
                }
            });
        } catch (RuntimeException) {
            // The connection has no transactions manager (a bare connection
            // outside a Laravel app): rollback callbacks are unavailable.
        }
    }

    private function dispatch(object $event): void
    {
        $container = Container::getInstance();

        if ($container->bound(Dispatcher::class)) {
            $container->make(Dispatcher::class)->dispatch($event);
        }
    }
}
