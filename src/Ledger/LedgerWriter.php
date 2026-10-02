<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Events\TransferCompleted;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Exceptions\InvalidTransfer;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\TransferResult;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only code that moves money. Every call posts one or more legs (a
 * balance change on a wallet plus the matching ledger entry) in a single
 * database transaction: a deposit or withdrawal is one leg, a transfer two.
 *
 * Each balance changes through a single guarded UPDATE: for a debit the
 * WHERE clause itself checks the minimum balance, so two concurrent debits
 * can never both pass the check (the row lock taken by the first UPDATE
 * makes the second re-evaluate against the committed balance). The wallet
 * instance's in-memory balance is never trusted; it is refreshed from the
 * database after the write.
 *
 * Lock order: the UPDATEs of a posting run in ascending wallet id order,
 * whatever the direction of the money, so two postings touching the same
 * wallets (A to B and B to A) always lock them in the same order and cannot
 * deadlock each other. The ledger rows are inserted after every UPDATE, in
 * the order of the legs (for a transfer: the sender's entry, then the
 * recipient's), so their ids follow the legs. If any UPDATE is refused the
 * whole transaction rolls back, including UPDATEs already applied to other
 * wallets.
 *
 * Writes go through the connection's plain query builder: the Eloquent
 * builders of both models refuse them by design.
 *
 * Everything here works in integer minor units (the stored values): the
 * models convert to and from major units at the edges, and the wallet
 * instances are read and synced through their raw attributes.
 *
 * In-memory state and outer transactions: after a successful write every
 * wallet instance involved holds its new balance. When the write ran inside
 * an outer transaction, a rollback callback is registered on the innermost
 * enclosing transaction that puts the previous balance and updated_at back
 * on the instance if that transaction rolls back. Laravel only runs
 * rollback callbacks of the transaction that is rolled back (and its
 * still-open parents), so if the enclosing transaction is a savepoint that
 * commits and a transaction further out rolls back later, the instance
 * keeps the new value; refresh() it in that case.
 *
 * Idempotency: when a key is given, a ledger entry already recorded on the
 * wallet under that key is returned instead of writing again, provided it
 * describes the same operation (same Fingerprint); otherwise
 * IdempotencyConflict is thrown. A replay changes nothing: no balance
 * change, no new row, no event, and the wallet instances are left as they
 * are (refresh() them to see the current balance). The key is only stored
 * with a successful write, so a failed attempt (e.g. InsufficientFunds) can
 * be retried with the same key. Two concurrent requests with the same key
 * both pass the first lookup; the unique index on (wallet_id,
 * idempotency_key) makes the second insert fail, which rolls back its
 * transaction (or savepoint, inside an outer transaction) including every
 * balance change, and the winner's entry is then looked up again.
 *
 * For a transfer the key belongs to the sender: it is stored on the
 * sender's entry only (the recipient's entry has no key), so two senders
 * may use the same key towards the same recipient.
 *
 * @internal
 */
final class LedgerWriter
{
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    /**
     * How many times a transfer is attempted when the database reports a
     * deadlock or another concurrency error. Only applies at the top level:
     * inside an outer transaction Laravel rethrows instead of retrying.
     */
    public const TRANSFER_ATTEMPTS = 3;

    /**
     * Applies $delta (in minor units; positive credit, negative debit) to the
     * wallet and records the ledger entry.
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

        self::assertSaved($wallet);

        $leg = new Leg($wallet, $delta, $details);

        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);

            $leg = new Leg($wallet, $delta, $details, $idempotencyKey, Fingerprint::for($delta, $details));

            if ($existing = $this->findByKey($wallet, $idempotencyKey, lock: false)) {
                return $this->replay($leg, $existing);
            }
        }

        try {
            [$transaction] = $this->post([$leg]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->replay($leg, $this->winner($leg, $e), $e);
        }

        return $transaction;
    }

    /**
     * Moves $amount (in minor units) from $sender to $recipient: a
     * transfer_out entry on the sender and a transfer_in entry on the
     * recipient, sharing a group uuid.
     *
     * @throws InvalidAmount when the amount is not positive
     * @throws InvalidTransfer when the wallets are the same or differ in currency, scale or connection
     * @throws InsufficientFunds when the sender would drop below its minimum balance
     * @throws BalanceOverflow when the recipient's balance would overflow
     * @throws WalletException when a wallet is not saved or no longer exists
     * @throws InvalidTransaction when the idempotency key is invalid
     * @throws IdempotencyConflict when the key was already used on the sender for a different operation
     */
    public function transfer(
        Wallet $sender,
        Wallet $recipient,
        int $amount,
        Details $out,
        Details $in,
        ?string $idempotencyKey = null,
    ): TransferResult {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive(Amount::format($amount, $sender->scale()));
        }

        self::assertSaved($sender);
        self::assertSaved($recipient);
        self::assertTransferable($sender, $recipient);

        $outLeg = new Leg($sender, -$amount, $out);
        $inLeg = new Leg($recipient, $amount, $in);

        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);

            $outLeg = new Leg($sender, -$amount, $out, $idempotencyKey, Fingerprint::for(-$amount, $out, [
                'operation' => 'transfer',
                'recipient_wallet' => (string) $recipient->getKey(),
                'recipient_description' => $in->description,
            ]));

            if ($existing = $this->findByKey($sender, $idempotencyKey, lock: false)) {
                return $this->replayTransfer($outLeg, $inLeg, $existing, lock: false);
            }
        }

        // Time-ordered, so the group index stays append-mostly.
        $group = (string) Str::uuid7();

        try {
            [$outTransaction, $inTransaction] = $this->post([$outLeg, $inLeg], $group, self::TRANSFER_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            return $this->replayTransfer($outLeg, $inLeg, $this->winner($outLeg, $e), lock: true, previous: $e);
        }

        $result = new TransferResult($outTransaction, $inTransaction, $group);

        $this->dispatch(new TransferCompleted($result));

        return $result;
    }

    /**
     * Writes the legs in one database transaction, then syncs the wallet
     * instances and dispatches TransactionRecorded for each entry, in leg
     * order.
     *
     * @param  non-empty-list<Leg>  $legs  all on the same connection
     * @param  int<1, max>  $attempts
     * @return non-empty-list<WalletTransaction> in leg order
     */
    private function post(array $legs, ?string $group = null, int $attempts = 1): array
    {
        $connection = $legs[0]->wallet->getConnection();
        $now = $legs[0]->wallet->freshTimestamp();

        $previous = [];

        foreach ($legs as $i => $leg) {
            $timestampColumn = self::timestampColumn($leg->wallet);

            $previous[$i] = [
                $leg->wallet->balanceInMinor(),
                $timestampColumn,
                $timestampColumn === null ? null : ($leg->wallet->getAttributes()[$timestampColumn] ?? null),
            ];
        }

        [$transactions, $balances] = $connection->transaction(
            fn (Connection $connection): array => $this->write($connection, $legs, $group, $now),
            $attempts,
        );

        // Legs on the same wallet were applied in leg order, so the last one
        // synced leaves the wallet's final balance on the instance.
        foreach ($legs as $i => $leg) {
            [$previousBalance, $timestampColumn, $previousTimestamp] = $previous[$i];

            $this->syncWallet(
                $leg->wallet,
                $balances[$i],
                $timestampColumn,
                $timestampColumn === null ? null : $leg->wallet->fromDateTime($now),
            );

            $this->restoreOnRollback($connection, $leg->wallet, $leg->delta, $previousBalance, $timestampColumn, $previousTimestamp, $now);
        }

        foreach ($transactions as $transaction) {
            $this->dispatch(new TransactionRecorded($transaction));
        }

        return $transactions;
    }

    /**
     * @param  non-empty-list<Leg>  $legs
     * @return array{non-empty-list<WalletTransaction>, array<int, int>} the entries in leg order, and the balance after each leg by leg index
     */
    private function write(Connection $connection, array $legs, ?string $group, DateTimeInterface $now): array
    {
        // Ascending wallet id. usort() is stable, so legs on the same wallet
        // keep their order and its balance_after chain follows the ids.
        $order = array_keys($legs);
        usort($order, fn (int $a, int $b): int => $legs[$a]->wallet->getKey() <=> $legs[$b]->wallet->getKey());

        $balances = [];

        foreach ($order as $i) {
            $balances[$i] = $this->apply($connection, $legs[$i], $now);
        }

        $transactions = [];

        foreach ($legs as $i => $leg) {
            $transactions[] = $this->insertTransaction($connection, $leg, $balances[$i], $group, $now);
        }

        return [$transactions, $balances];
    }

    /**
     * Runs the leg's guarded UPDATE and returns the balance it produced.
     */
    private function apply(Connection $connection, Leg $leg, DateTimeInterface $now): int
    {
        $wallet = $leg->wallet;
        $delta = $leg->delta;
        $timestampColumn = self::timestampColumn($wallet);
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
        return (int) $this->walletRow($connection, $wallet)->value('balance');
    }

    private function insertTransaction(
        Connection $connection,
        Leg $leg,
        int $balance,
        ?string $group,
        DateTimeInterface $now,
    ): WalletTransaction {
        $wallet = $leg->wallet;
        $details = $leg->details;

        $class = Models::transaction();
        $transaction = new $class;
        $transaction->setConnection($wallet->getConnectionName());

        // forceFill() runs the values through the model's casts (meta to
        // JSON, created_at to the model's date format).
        $transaction->forceFill([
            'wallet_id' => $wallet->getKey(),
            'type' => $details->type,
            'amount' => $leg->delta,
            'balance_after' => $balance,
            'reference_type' => $details->reference?->getMorphClass(),
            'reference_id' => $details->reference?->getKey(),
            'performed_by_type' => $details->performedBy?->getMorphClass(),
            'performed_by_id' => $details->performedBy?->getKey(),
            'description' => $details->description,
            'meta' => $details->meta === [] ? null : $details->meta,
            'idempotency_key' => $leg->idempotencyKey,
            'idempotency_hash' => $leg->idempotencyHash,
            'group_uuid' => $group,
            'created_at' => $now,
        ]);

        $keyName = $transaction->getKeyName();

        $id = $connection->table($transaction->getTable())->insertGetId($transaction->getAttributes(), $keyName);

        $transaction->setAttribute($keyName, $id);
        $transaction->exists = true;
        $transaction->wasRecentlyCreated = true;
        $transaction->syncOriginal();

        $this->attachRelations($transaction, $leg);

        return $transaction;
    }

    /**
     * @throws WalletException
     */
    private static function assertSaved(Wallet $wallet): void
    {
        if (! $wallet->exists || $wallet->getKey() === null) {
            throw new WalletException('Cannot record a transaction on an unsaved wallet.');
        }
    }

    /**
     * Checked on the instances, before any query. Currency and scale are
     * fixed once a wallet exists, so the instance's values can be trusted.
     *
     * @throws InvalidTransfer
     */
    private static function assertTransferable(Wallet $sender, Wallet $recipient): void
    {
        $senderConnection = self::connectionName($sender);
        $recipientConnection = self::connectionName($recipient);

        if ((string) $sender->getKey() === (string) $recipient->getKey()
            && $sender->getTable() === $recipient->getTable()
            && $senderConnection === $recipientConnection) {
            throw InvalidTransfer::sameWallet($sender);
        }

        if ($senderConnection !== $recipientConnection) {
            throw InvalidTransfer::connectionMismatch($sender, $recipient, $senderConnection, $recipientConnection);
        }

        if ($sender->getAttribute('currency') !== $recipient->getAttribute('currency')) {
            throw InvalidTransfer::currencyMismatch($sender, $recipient);
        }

        // Same currency normally means same scale; a wallet created before
        // the currency's scale was configured differently would not.
        if ($sender->scale() !== $recipient->scale()) {
            throw InvalidTransfer::scaleMismatch($sender, $recipient);
        }
    }

    /**
     * The wallet's connection name, with "the default connection" resolved
     * to its name without opening it.
     */
    private static function connectionName(Wallet $wallet): string
    {
        return $wallet->getConnectionName() ?? (string) Wallet::getConnectionResolver()?->getDefaultConnection();
    }

    private static function timestampColumn(Wallet $wallet): ?string
    {
        return $wallet->usesTimestamps() ? $wallet->getUpdatedAtColumn() : null;
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
     * level of an enclosing transaction. The wallet is not eager loaded: a
     * replay attaches the request's instance.
     */
    private function findByKey(Wallet $wallet, string $key, bool $lock): ?WalletTransaction
    {
        $class = Models::transaction();

        $query = (new $class)->setConnection($wallet->getConnectionName())->newQuery()
            ->without('wallet')
            ->where('wallet_id', $wallet->getKey())
            ->where('idempotency_key', $key);

        if ($lock) {
            $query->sharedLock();
        }

        return $query->first();
    }

    /**
     * After a unique violation: a concurrent request with the same key
     * committed first. Our transaction (or savepoint) has been rolled back,
     * every balance change included. The locking read sees the winner's
     * committed row even inside an outer REPEATABLE READ transaction, where
     * a plain read would still use the old snapshot. Rethrows the violation
     * when the leg has no key or no entry is found under it.
     */
    private function winner(Leg $leg, UniqueConstraintViolationException $e): WalletTransaction
    {
        if ($leg->idempotencyKey === null) {
            throw $e;
        }

        return $this->findByKey($leg->wallet, $leg->idempotencyKey, lock: true) ?? throw $e;
    }

    /**
     * Returns the entry already recorded under the key when it describes the
     * same operation. Nothing is written and no event is dispatched; the
     * wallet instance is not touched.
     *
     * @throws IdempotencyConflict
     */
    private function replay(Leg $leg, WalletTransaction $existing, ?UniqueConstraintViolationException $previous = null): WalletTransaction
    {
        $this->assertSameOperation($leg, $existing, $previous);

        // Equal fingerprints mean the same reference and actor, so the
        // request's models can stand in for the relations.
        $this->attachRelations($existing, $leg);

        return $existing;
    }

    /**
     * Returns the transfer already recorded under the sender's key when it
     * describes the same transfer (same fingerprint, which covers the
     * recipient wallet and both descriptions). Nothing is written, no event
     * is dispatched and neither wallet instance is touched.
     *
     * @throws IdempotencyConflict
     */
    private function replayTransfer(
        Leg $out,
        Leg $in,
        WalletTransaction $existing,
        bool $lock,
        ?UniqueConstraintViolationException $previous = null,
    ): TransferResult {
        $this->assertSameOperation($out, $existing, $previous);

        $key = (string) $out->idempotencyKey;
        $group = $existing->getRawOriginal('group_uuid');

        if ($existing->getRawOriginal('type') !== CoreType::TransferOut->value || ! is_string($group) || $group === '') {
            throw IdempotencyConflict::incompleteTransfer($out->wallet, $key, $existing, $previous);
        }

        $counterpart = $this->findTransferIn($existing, $in->wallet, $group, $lock)
            ?? throw IdempotencyConflict::incompleteTransfer($out->wallet, $key, $existing, $previous);

        // Equal fingerprints mean the same recipient, reference and actor.
        $this->attachRelations($existing, $out);
        $this->attachRelations($counterpart, $in);

        return new TransferResult($existing, $counterpart, $group, replayed: true);
    }

    /**
     * The recipient's entry of the transfer whose sender entry is $out.
     * Locking like the lookup of $out, for the same snapshot reasons.
     */
    private function findTransferIn(WalletTransaction $out, Wallet $recipient, string $group, bool $lock): ?WalletTransaction
    {
        $class = Models::transaction();
        $model = (new $class)->setConnection($out->getConnectionName());

        $query = $model->newQuery()
            ->without('wallet')
            ->where('group_uuid', $group)
            ->where('wallet_id', $recipient->getKey())
            ->where('type', CoreType::TransferIn->value)
            ->orderBy($model->getKeyName());

        if ($lock) {
            $query->sharedLock();
        }

        return $query->first();
    }

    /**
     * @throws IdempotencyConflict when the stored fingerprint differs from the leg's
     */
    private function assertSameOperation(Leg $leg, WalletTransaction $existing, ?UniqueConstraintViolationException $previous): void
    {
        $stored = $existing->getRawOriginal('idempotency_hash');

        if (! is_string($stored) || $leg->idempotencyHash === null || ! hash_equals($stored, $leg->idempotencyHash)) {
            throw IdempotencyConflict::for($leg->wallet, (string) $leg->idempotencyKey, $existing, $previous);
        }
    }

    private function attachRelations(WalletTransaction $transaction, Leg $leg): void
    {
        $transaction->setRelation('wallet', $leg->wallet);
        $transaction->setRelation('reference', $leg->details->reference);
        $transaction->setRelation('performedBy', $leg->details->performedBy);
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
     * The balance attribute has no setter: the raw integer is stored and the
     * accessor formats it on read.
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
                $balance = $wallet->getAttributes()['balance'] ?? null;

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
