<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;

/**
 * Thrown when code tries to change a wallet's money state, identity or
 * status (balance, holder, name, currency, scale, status) or delete it
 * through Eloquent.
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

    public static function minBalanceThroughQuery(string $operation): self
    {
        return new self(sprintf(
            'Cannot %s [min_balance] on wallets through a query: a query would write raw minor units. Use $wallet->setMinBalance() with the new value in major units (e.g. -100 for -100.00).',
            $operation,
        ));
    }

    public static function cannotDelete(Wallet $wallet): self
    {
        return new self(sprintf(
            'Wallet [%s] cannot be deleted: its ledger must stay intact. Freeze it with $wallet->freeze() or close it with $wallet->close() instead.',
            (string) $wallet->getKey(),
        ));
    }

    public static function cannotBulkWrite(string $operation): self
    {
        return new self(sprintf(
            'Wallets cannot be written with [%s]. Create wallets with $holder->wallet() or $holder->createWallet(), and change only meta on existing ones (min_balance through $wallet->setMinBalance(), the status through $wallet->freeze(), unfreeze() and close()).',
            $operation,
        ));
    }

    public static function mustStartEmpty(): self
    {
        return new self(
            'A wallet must be created with a zero balance. Fund it afterwards by recording a ledger entry.'
        );
    }

    public static function mustStartActive(): self
    {
        return new self(
            'A wallet must be created active. Freeze or close it afterwards with $wallet->freeze() or $wallet->close().'
        );
    }

    private static function advice(string $column): string
    {
        return match ($column) {
            'balance' => 'Balances only change by recording a ledger entry.',
            'status' => 'The status only changes through $wallet->freeze(), $wallet->unfreeze() and $wallet->close().',
            default => 'A wallet\'s holder, name, currency and scale are fixed at creation; create a new wallet instead.',
        };
    }
}
