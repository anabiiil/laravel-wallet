<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;
use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when a wallet's status cannot change as asked: the wallet is
 * closed (which is permanent), it still holds money and cannot be closed,
 * or the reason / actor given with the change are invalid. The status is
 * read from the database, not from the (possibly stale) wallet instance.
 * Nothing is written.
 */
class InvalidStatusTransition extends WalletException
{
    public function __construct(
        string $message,
        public readonly Wallet $wallet,
        public readonly WalletStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function fromClosed(Wallet $wallet, WalletStatus $to): self
    {
        return new self(sprintf(
            'Cannot change wallet [%s] to [%s]: the wallet is closed, and closing is permanent. Use another wallet.',
            (string) $wallet->getKey(),
            $to->value,
        ), $wallet, $to);
    }

    /**
     * @param  int  $balance  in minor units, as read from the database
     */
    public static function nonZeroBalance(Wallet $wallet, int $balance): self
    {
        return new self(sprintf(
            'Cannot close wallet [%s]: its balance is %s %s and only an empty wallet can be closed. Move the money out (or settle the debt) until the balance is zero first.',
            (string) $wallet->getKey(),
            Amount::format($balance, $wallet->scale()),
            (string) $wallet->getAttribute('currency'),
        ), $wallet, WalletStatus::Closed);
    }

    public static function unknownStatus(Wallet $wallet, WalletStatus $to, string $status): self
    {
        return new self(sprintf(
            'Cannot change wallet [%s] to [%s]: its stored status [%s] is not a valid status. Run wallet:check to inspect it.',
            (string) $wallet->getKey(),
            $to->value,
            $status,
        ), $wallet, $to);
    }

    public static function contended(Wallet $wallet, WalletStatus $to, int $attempts): self
    {
        return new self(sprintf(
            'Cannot change wallet [%s] to [%s]: its status kept changing concurrently (%d attempts). Try again.',
            (string) $wallet->getKey(),
            $to->value,
            $attempts,
        ), $wallet, $to);
    }

    public static function reasonTooLong(Wallet $wallet, WalletStatus $to, int $length, int $max): self
    {
        return new self(sprintf(
            'The reason for changing wallet [%s] to [%s] is %d characters long: at most %d are allowed.',
            (string) $wallet->getKey(),
            $to->value,
            $length,
            $max,
        ), $wallet, $to);
    }

    public static function unsavedPerformer(Wallet $wallet, WalletStatus $to, Model $performedBy): self
    {
        return new self(sprintf(
            'The performedBy model [%s] must be saved before it is attached to the status change of wallet [%s] to [%s].',
            $performedBy::class,
            (string) $wallet->getKey(),
            $to->value,
        ), $wallet, $to);
    }
}
