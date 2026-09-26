<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;

/**
 * Thrown when a debit would take a wallet below its minimum balance. The
 * numbers are read from the database at the moment of the refusal, not
 * from the (possibly stale) wallet instance.
 */
class InsufficientFunds extends WalletException
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly int $requested,
        public readonly int $balance,
        public readonly int $minBalance,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Insufficient funds in wallet [%s]: requested %d, available %d (balance %d, minimum balance %d).',
            (string) $wallet->getKey(),
            $requested,
            $available,
            $balance,
            $minBalance,
        ));
    }

    public static function for(Wallet $wallet, int $requested, int $balance, int $minBalance): self
    {
        return new self($wallet, $requested, $balance, $minBalance, max(0, $balance - $minBalance));
    }
}
