<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;

/**
 * Thrown when money would move on a closed wallet, in either direction,
 * forced reversals included. Closing is permanent. The status is read from
 * the database at the moment of the refusal, not from the (possibly stale)
 * wallet instance. Nothing is written.
 */
class WalletClosed extends WalletException
{
    /**
     * @param  string  $amount  the refused movement, a positive decimal string in major units
     * @param  bool  $credit  whether money was coming in (true) or going out (false)
     */
    public function __construct(
        public readonly Wallet $wallet,
        public readonly string $amount,
        public readonly bool $credit,
    ) {
        parent::__construct(sprintf(
            'Cannot %s %s %s %s wallet [%s]: the wallet is closed and accepts no movements. Closing is permanent: use another wallet.',
            $credit ? 'credit' : 'debit',
            $amount,
            (string) $wallet->getAttribute('currency'),
            $credit ? 'to' : 'from',
            (string) $wallet->getKey(),
        ));
    }

    /**
     * @param  int  $delta  in minor units: positive for a credit, negative for a debit; never PHP_INT_MIN
     */
    public static function for(Wallet $wallet, int $delta): self
    {
        return new self($wallet, Amount::format(abs($delta), $wallet->scale()), $delta > 0);
    }
}
