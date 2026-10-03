<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;

/**
 * Thrown when a credit would push a balance past the largest value a signed
 * 64-bit integer of minor units can hold, or a debit that skips the minimum
 * balance check (a forced reversal) below the smallest one; $maximum is
 * then that smallest value. The amounts are decimal strings in major units
 * with the wallet's scale.
 */
class BalanceOverflow extends WalletException
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly string $amount,
        public readonly string $balance,
        public readonly string $maximum,
        ?string $message = null,
    ) {
        $currency = (string) $wallet->getAttribute('currency');

        parent::__construct($message ?? sprintf(
            'Cannot credit %s %s to wallet [%s]: its balance of %s %s would exceed the maximum of %s %s.',
            $amount,
            $currency,
            (string) $wallet->getKey(),
            $balance,
            $currency,
            $maximum,
            $currency,
        ));
    }

    /**
     * @param  int  $amount  in minor units
     * @param  int  $balance  in minor units
     */
    public static function for(Wallet $wallet, int $amount, int $balance): self
    {
        $scale = $wallet->scale();

        return new self(
            $wallet,
            Amount::format($amount, $scale),
            Amount::format($balance, $scale),
            Amount::format(PHP_INT_MAX, $scale),
        );
    }

    /**
     * @param  int  $amount  in minor units, positive
     * @param  int  $balance  in minor units
     */
    public static function forDebit(Wallet $wallet, int $amount, int $balance): self
    {
        $scale = $wallet->scale();
        $currency = (string) $wallet->getAttribute('currency');
        $minimum = Amount::format(PHP_INT_MIN, $scale);

        return new self(
            $wallet,
            Amount::format($amount, $scale),
            Amount::format($balance, $scale),
            $minimum,
            sprintf(
                'Cannot debit %s %s from wallet [%s]: its balance of %s %s would go below the minimum of %s %s.',
                Amount::format($amount, $scale),
                $currency,
                (string) $wallet->getKey(),
                Amount::format($balance, $scale),
                $currency,
                $minimum,
                $currency,
            ),
        );
    }
}
