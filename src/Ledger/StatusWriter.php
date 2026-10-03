<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Events\WalletStatusChanged;
use Anabil\Wallet\Exceptions\InvalidStatusTransition;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

/**
 * The only code that changes a wallet's status (Wallet::freeze(),
 * unfreeze() and close()).
 *
 * Each attempt is one guarded UPDATE of the wallet row through the
 * connection's plain query builder: it sets the new status only WHERE the
 * row still has the status it is expected to come from (and, to close it,
 * WHERE balance = 0). It takes the same row lock as the ledger's money
 * UPDATEs, so a status change and a movement are serialised: a debit that
 * commits first makes a close fail on the balance, and a close or freeze
 * that commits first makes every later movement it forbids fail on its
 * status guard (see LedgerWriter).
 *
 * When the UPDATE matches no row the row is read again to explain why: the
 * wallet already has the requested status (a no-op: no event, nothing
 * written), it is closed (permanent), it still holds money (close), or its
 * status changed between our UPDATE and our read, in which case the UPDATE
 * is tried again from the status just read, a bounded number of times. The
 * expected status is taken from what the row says, never from the
 * instance, so the event always reports the status actually left.
 *
 * In-memory state and outer transactions work like LedgerWriter's: the
 * instance gets the new status and updated_at (clean), and a rollback of
 * the enclosing transaction puts the previous ones back.
 *
 * @internal
 */
final class StatusWriter
{
    public const MAX_REASON_LENGTH = 255;

    /**
     * How many guarded UPDATEs are tried when the status keeps changing
     * concurrently between an UPDATE and the read that explains it.
     */
    public const ATTEMPTS = 5;

    /**
     * @throws InvalidStatusTransition when the wallet is closed, still holds money (close), or the reason / actor are invalid
     * @throws WalletException when the wallet is not saved or no longer exists
     */
    public function transition(Wallet $wallet, WalletStatus $to, ?string $reason, ?Model $performedBy): Wallet
    {
        if (! $wallet->exists || $wallet->getKey() === null) {
            throw new WalletException('Cannot change the status of an unsaved wallet.');
        }

        if ($reason !== null && trim($reason) === '') {
            $reason = null;
        }

        if ($reason !== null && ($length = mb_strlen($reason)) > self::MAX_REASON_LENGTH) {
            throw InvalidStatusTransition::reasonTooLong($wallet, $to, $length, self::MAX_REASON_LENGTH);
        }

        if ($performedBy !== null && (! $performedBy->exists || $performedBy->getKey() === null)) {
            throw InvalidStatusTransition::unsavedPerformer($wallet, $to, $performedBy);
        }

        $sources = self::sources($to);
        $connection = $wallet->getConnection();
        $timestampColumn = $wallet->usesTimestamps() ? $wallet->getUpdatedAtColumn() : null;

        // A first guess only; the row decides.
        $current = WalletStatus::tryFrom((string) ($wallet->getAttributes()['status'] ?? ''));
        $from = $current !== null && in_array($current, $sources, true) ? $current : $sources[0];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $now = $wallet->freshTimestamp();

            $query = $this->walletRow($connection, $wallet)->where('status', $from->value);

            if ($to === WalletStatus::Closed) {
                $query->where('balance', 0);
            }

            $values = ['status' => $to->value];

            if ($timestampColumn !== null) {
                $values[$timestampColumn] = $wallet->fromDateTime($now);
            }

            if ($query->update($values) > 0) {
                $undo = $wallet->recordStatusChange($from, $to, $timestampColumn, $now);

                $this->undoOnRollback($connection, $undo);

                $this->dispatch(new WalletStatusChanged($wallet, $from, $to, $reason, $performedBy));

                return $wallet;
            }

            $row = $this->walletRow($connection, $wallet)->first(['status', 'balance']);

            if ($row === null) {
                throw new WalletException(sprintf('Wallet [%s] no longer exists.', (string) $wallet->getKey()));
            }

            $status = WalletStatus::tryFrom((string) $row->status)
                ?? throw InvalidStatusTransition::unknownStatus($wallet, $to, (string) $row->status);

            if ($status === $to) {
                // Nothing to do: the instance learns the stored status.
                $wallet->syncStatus($to);

                return $wallet;
            }

            if ($status === WalletStatus::Closed) {
                throw InvalidStatusTransition::fromClosed($wallet, $to);
            }

            if ($to === WalletStatus::Closed && (int) $row->balance !== 0) {
                throw InvalidStatusTransition::nonZeroBalance($wallet, (int) $row->balance);
            }

            // The status moved between our UPDATE and this read (or the
            // first guess was wrong): try again from what the row says.
            $from = $status;
        }

        throw InvalidStatusTransition::contended($wallet, $to, self::ATTEMPTS);
    }

    /**
     * The statuses a wallet may move to $to from. Closed is terminal.
     *
     * @return non-empty-list<WalletStatus>
     */
    private static function sources(WalletStatus $to): array
    {
        return match ($to) {
            WalletStatus::Frozen => [WalletStatus::Active],
            WalletStatus::Active => [WalletStatus::Frozen],
            WalletStatus::Closed => [WalletStatus::Active, WalletStatus::Frozen],
        };
    }

    private function walletRow(Connection $connection, Wallet $wallet): QueryBuilder
    {
        return $connection->table($wallet->getTable())->where($wallet->getKeyName(), $wallet->getKey());
    }

    /**
     * @param  Closure(): void  $undo
     */
    private function undoOnRollback(Connection $connection, Closure $undo): void
    {
        if ($connection->transactionLevel() === 0) {
            return;
        }

        try {
            $connection->afterRollBack($undo);
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
