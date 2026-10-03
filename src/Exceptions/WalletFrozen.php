<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;

/**
 * Thrown when money would leave a frozen wallet (a withdrawal, the sending
 * side of a transfer, or the debit side of a reversal that is not forced).
 * A frozen wallet still accepts credits. The status is read from the
 * database at the moment of the refusal, not from the (possibly stale)
 * wallet instance. Nothing is written.
 */
class WalletFrozen extends WalletException
{
    /**
     * @param  string  $amount  the refused debit, a positive decimal string in major units
     */
    public function __construct(
        public readonly Wallet $wallet,
        public readonly string $amount,
    ) {
        parent::__construct(sprintf(
            'Cannot debit %s %s from wallet [%s]: the wallet is frozen and only accepts credits. Unfreeze it with $wallet->unfreeze() first (a chargeback can still go through with reverse(force: true)).',
            $amount,
            (string) $wallet->getAttribute('currency'),
            (string) $wallet->getKey(),
        ));
    }

    /**
     * @param  int  $amount  in minor units, positive
     */
    public static function forDebit(Wallet $wallet, int $amount): self
    {
        return new self($wallet, Amount::format($amount, $wallet->scale()));
    }
}
