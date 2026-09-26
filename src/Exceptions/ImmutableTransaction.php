<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\WalletTransaction;

/**
 * Thrown when code tries to create, change or delete a ledger entry through
 * Eloquent. The ledger is append-only and written by the package itself.
 */
class ImmutableTransaction extends WalletException
{
    public static function cannotCreate(): self
    {
        return new self(
            'Wallet transactions cannot be created through Eloquent. Money moves only through the wallet API, which records ledger entries itself.'
        );
    }

    public static function cannotUpdate(?WalletTransaction $transaction = null): self
    {
        return new self(sprintf(
            'Wallet transaction%s cannot be changed: the ledger is append-only. Record a correcting or reversing entry instead.',
            self::describe($transaction),
        ));
    }

    public static function cannotDelete(?WalletTransaction $transaction = null): self
    {
        return new self(sprintf(
            'Wallet transaction%s cannot be deleted: the ledger is append-only. Record a reversing entry instead.',
            self::describe($transaction),
        ));
    }

    public static function cannotBulkWrite(string $operation): self
    {
        return new self(sprintf(
            'Wallet transactions cannot be written with [%s]: the ledger is append-only. Record a correcting or reversing entry through the wallet API instead.',
            $operation,
        ));
    }

    private static function describe(?WalletTransaction $transaction): string
    {
        return $transaction?->getKey() !== null
            ? sprintf(' [%s]', (string) $transaction->getKey())
            : '';
    }
}
