<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Models;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * @internal
 */
final class LedgerWriter
{
    /**
     * Applies $delta (positive credit, negative debit) to the wallet and
     * records the ledger entry.
     *
     * @throws InsufficientFunds when a debit would take the balance below the minimum balance
     * @throws BalanceOverflow when a credit would overflow the balance
     * @throws WalletException when the wallet is not saved or no longer exists
     */
    public function record(Wallet $wallet, int $delta, Details $details): WalletTransaction
    {
        if ($delta === 0) {
            throw InvalidAmount::zero();
        }

        if (! $wallet->exists || $wallet->getKey() === null) {
            throw new WalletException('Cannot record a transaction on an unsaved wallet.');
        }

        $connection = $wallet->getConnection();
        $timestampColumn = $wallet->usesTimestamps() ? $wallet->getUpdatedAtColumn() : null;
        $now = $wallet->freshTimestamp();

        $previousBalance = (int) $wallet->getAttribute('balance');
        $previousTimestamp = $timestampColumn === null ? null : ($wallet->getAttributes()[$timestampColumn] ?? null);

        [$transaction, $balance] = $connection->transaction(
            fn (Connection $connection): array => $this->write($connection, $wallet, $delta, $details, $timestampColumn, $now),
        );

        $this->syncWallet($wallet, $balance, $timestampColumn, $timestampColumn === null ? null : $wallet->fromDateTime($now));

        $this->restoreOnRollback($connection, $wallet, $delta, $previousBalance, $timestampColumn, $previousTimestamp, $now);

        $this->dispatch(new TransactionRecorded($transaction));

        return $transaction;
    }

    /**
     * @return array{WalletTransaction, int}
     */
    private function write(
        Connection $connection,
        Wallet $wallet,
        int $delta,
        Details $details,
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

        return [$this->insertTransaction($connection, $wallet, $delta, $balance, $details, $now), $balance];
    }

    private function insertTransaction(
        Connection $connection,
        Wallet $wallet,
        int $delta,
        int $balance,
        Details $details,
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
