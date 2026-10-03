<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\WalletTransaction;
use Throwable;

/**
 * Thrown when a ledger entry cannot be reversed. Nothing is written.
 *
 * $transaction is the entry the refusal is about (for a transfer, the leg
 * that was already reversed may be the counterpart of the entry reverse()
 * was called on); $reversal is the reversal that already exists, when that
 * is the reason.
 */
class InvalidReversal extends WalletException
{
    public function __construct(
        string $message,
        public readonly ?WalletTransaction $transaction = null,
        public readonly ?WalletTransaction $reversal = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unsaved(): self
    {
        return new self('Cannot reverse a wallet transaction that has not been recorded.');
    }

    public static function cannotReverseReversal(WalletTransaction $transaction): self
    {
        return new self(sprintf(
            'Wallet transaction [%s] is a reversal and cannot be reversed itself. Record a new entry instead.',
            self::id($transaction),
        ), $transaction);
    }

    public static function alreadyReversed(WalletTransaction $transaction, WalletTransaction $reversal, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Wallet transaction [%s] has already been reversed by transaction [%s]: an entry can be reversed only once.',
            self::id($transaction),
            self::id($reversal),
        ), $transaction, $reversal, $previous);
    }

    /**
     * A transfer is reversed as a whole, which needs both of its legs: the
     * group must hold exactly one transfer_out and one transfer_in entry
     * that cancel each other out.
     */
    public static function incompleteTransfer(WalletTransaction $transaction): self
    {
        return new self(sprintf(
            'Wallet transaction [%s] is a transfer leg whose counterpart is missing or does not match, so the transfer cannot be reversed. Run wallet:check to inspect the ledger.',
            self::id($transaction),
        ), $transaction);
    }

    /**
     * The stored amount cannot be negated (zero, or the smallest 64-bit
     * integer, which only a manual edit of the table can produce).
     */
    public static function unreversibleAmount(WalletTransaction $transaction): self
    {
        return new self(sprintf(
            'Wallet transaction [%s] has an amount that cannot be reversed. Run wallet:check to inspect the ledger.',
            self::id($transaction),
        ), $transaction);
    }

    private static function id(WalletTransaction $transaction): string
    {
        $key = $transaction->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
