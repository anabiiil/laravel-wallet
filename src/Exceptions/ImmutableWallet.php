<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;

/**
 * Thrown when code tries to change a wallet's money state or identity
 * (balance, holder, name, currency) or delete it through Eloquent.
 */
class ImmutableWallet extends WalletException
{
    public static function protectedAttribute(Wallet $wallet, string $column): self
    {
        return new self(sprintf(
            'Cannot change [%s] on wallet [%s]: it is protected. %s',
            $column,
            (string) $wallet->getKey(),
            self::advice($column),
        ));
    }

    public static function protectedColumn(string $column, string $operation): self
    {
        return new self(sprintf(
            'Cannot %s [%s] on wallets through a query: it is protected. %s',
            $operation,
            $column,
            self::advice($column),
        ));
    }

    public static function cannotDelete(Wallet $wallet): self
    {
        return new self(sprintf(
            'Wallet [%s] cannot be deleted: its ledger must stay intact. Freeze or close it by changing its status instead.',
            (string) $wallet->getKey(),
        ));
    }

    public static function cannotBulkWrite(string $operation): self
    {
        return new self(sprintf(
            'Wallets cannot be written with [%s]. Create wallets with $holder->wallet() or $holder->createWallet(), and change only meta, status and min_balance on existing ones.',
            $operation,
        ));
    }

    public static function mustStartEmpty(): self
    {
        return new self(
            'A wallet must be created with a zero balance. Fund it afterwards by recording a ledger entry.'
        );
    }

    private static function advice(string $column): string
    {
        return $column === 'balance'
            ? 'Balances only change by recording a ledger entry.'
            : 'A wallet\'s holder, name and currency are fixed at creation; create a new wallet instead.';
    }
}
