<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

/**
 * Thrown when an amount cannot be used: it is not positive, not a valid
 * number, has more decimal places than the wallet, or is too large. Amounts
 * are never rounded. Nothing is written.
 */
class InvalidAmount extends WalletException
{
    /**
     * @param  string  $amount  the amount as the caller would read it, e.g. "-5.00"
     */
    public static function notPositive(string $amount): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: amounts must be greater than zero. Use deposit() to add money and withdraw() to take it out.',
            $amount,
        ));
    }

    public static function zero(): self
    {
        return new self('A ledger entry must move money: its amount cannot be zero.');
    }

    public static function malformed(string $amount): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: pass an int, a float, or a plain decimal string such as "100.50" (digits with an optional leading "-" and an optional "." followed by digits; no spaces, "+", exponents or thousands separators).',
            $amount,
        ));
    }

    public static function invalidType(mixed $amount): self
    {
        return new self(sprintf(
            'Invalid amount of type [%s]: pass an int, a float, or a decimal string such as "100.50".',
            get_debug_type($amount),
        ));
    }

    public static function tooPrecise(string $amount, int $scale): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: this wallet uses %d decimal places and amounts are never rounded. Round it to at most %d decimal places first.',
            $amount,
            $scale,
            $scale,
        ));
    }

    public static function tooLarge(string $amount): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: it is too large to be stored (balances are 64-bit integers in minor units).',
            $amount,
        ));
    }

    public static function notFinite(float $amount): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: NAN and INF are not amounts.',
            (string) $amount,
        ));
    }

    public static function floatTooLarge(float $amount, int $scale): self
    {
        return new self(sprintf(
            'Invalid amount [%s]: a float this large cannot hold %d decimal places exactly. Pass the amount as a string instead, e.g. "90071992547409.91".',
            (string) $amount,
            $scale,
        ));
    }
}
