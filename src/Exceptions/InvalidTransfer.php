<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when a transfer cannot happen between the given wallets. Nothing
 * is written.
 */
class InvalidTransfer extends WalletException
{
    public static function sameWallet(Wallet $wallet): self
    {
        return new self(sprintf(
            'Cannot transfer from wallet [%s] to itself.',
            (string) $wallet->getKey(),
        ));
    }

    public static function currencyMismatch(Wallet $sender, Wallet $recipient): self
    {
        return new self(sprintf(
            'Cannot transfer from wallet [%s] (%s) to wallet [%s] (%s): both wallets must use the same currency.',
            (string) $sender->getKey(),
            (string) $sender->getAttribute('currency'),
            (string) $recipient->getKey(),
            (string) $recipient->getAttribute('currency'),
        ));
    }

    public static function connectionMismatch(Wallet $sender, Wallet $recipient, string $senderConnection, string $recipientConnection): self
    {
        return new self(sprintf(
            'Cannot transfer from wallet [%s] on connection [%s] to wallet [%s] on connection [%s]: both wallets must live on the same database connection.',
            (string) $sender->getKey(),
            $senderConnection,
            (string) $recipient->getKey(),
            $recipientConnection,
        ));
    }

    public static function recipientHoldsNoWallet(Model $recipient): self
    {
        return new self(sprintf(
            'Cannot transfer to [%s]: it does not use the HasWallet trait. Pass a wallet or a model that holds wallets.',
            $recipient::class,
        ));
    }
}
