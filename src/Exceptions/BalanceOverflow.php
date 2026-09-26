<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;

/**
 * Thrown when a credit would push a balance past the largest value a signed
 * 64-bit integer can hold.
 */
class BalanceOverflow extends WalletException
{
    public static function for(Wallet $wallet, int $amount, int $balance): self
    {
        return new self(sprintf(
            'Cannot credit %d to wallet [%s]: its balance of %d would exceed the maximum of %d.',
            $amount,
            (string) $wallet->getKey(),
            $balance,
            PHP_INT_MAX,
        ));
    }
}
