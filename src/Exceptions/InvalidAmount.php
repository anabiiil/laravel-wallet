<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

class InvalidAmount extends WalletException
{
    public static function notPositive(int $amount): self
    {
        return new self(sprintf(
            'Invalid amount [%d]: amounts are positive integers in minor units (e.g. 1050 for 10.50). Use deposit() to add money and withdraw() to take it out.',
            $amount,
        ));
    }

    public static function zero(): self
    {
        return new self('A ledger entry must move money: its amount cannot be zero.');
    }
}
