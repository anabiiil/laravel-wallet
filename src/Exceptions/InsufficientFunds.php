<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;

/**
 * Thrown when a debit would take a wallet below its minimum balance. The
 * numbers are read from the database at the moment of the refusal, not
 * from the (possibly stale) wallet instance. They are decimal strings in
 * major units with the wallet's scale, like the wallet's own attributes.
 */
class InsufficientFunds extends WalletException
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly string $requested,
        public readonly string $balance,
        public readonly string $minBalance,
        public readonly string $available,
    ) {
        $currency = (string) $wallet->getAttribute('currency');

        parent::__construct(sprintf(
            'Insufficient funds in wallet [%s]: requested %s %s, available %s %s (balance %s %s, minimum balance %s %s).',
            (string) $wallet->getKey(),
            $requested,
            $currency,
            $available,
            $currency,
            $balance,
            $currency,
            $minBalance,
            $currency,
        ));
    }

    /**
     * @param  int  $requested  in minor units
     * @param  int  $balance  in minor units
     * @param  int  $minBalance  in minor units
     */
    public static function for(Wallet $wallet, int $requested, int $balance, int $minBalance): self
    {
        $scale = $wallet->scale();

        return new self(
            $wallet,
            Amount::format($requested, $scale),
            Amount::format($balance, $scale),
            Amount::format($minBalance, $scale),
            $balance > $minBalance ? Amount::difference($balance, $minBalance, $scale) : Amount::format(0, $scale),
        );
    }
}
