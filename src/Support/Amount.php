<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Anabil\Wallet\Exceptions\InvalidAmount;
use InvalidArgumentException;

/**
 * Exact conversion between amounts in major units (what callers pass and
 * read: 250, 100.50, "100.50") and integers in minor units (what is stored:
 * 25000, 10050). Nothing is ever rounded: an amount that cannot be written
 * exactly with the given number of decimal places is refused. Amounts are
 * converted with string and integer operations only, never float
 * arithmetic.
 */
final class Amount
{
    /**
     * Accepted decimal strings: digits, an optional leading minus and an
     * optional fraction. No spaces, "+", exponents, thousands separators,
     * leading or trailing ".".
     */
    public const PATTERN = '/^-?\d+(\.\d+)?$/D';

    /**
     * The largest scale whose factor (10 ** scale) fits a 64-bit integer.
     */
    private const MAX_SCALE = 18;

    /**
     * Floats are exact integers up to 2 ** 53; past it (divided by the
     * scale's factor) the decimals a caller meant are already lost.
     */
    private const FLOAT_EXACT_LIMIT = 2 ** 53;

    /**
     * Major units to minor units, exactly.
     *
     * Floats are read through their shortest round-trip decimal form (the
     * digits PHP prints with serialize_precision = -1, whatever the ini
     * settings): 100.5 reads as "100.5", but 0.1 + 0.2 reads as
     * "0.30000000000000004" and is refused as too precise.
     *
     * @throws InvalidAmount when the amount is malformed, has more decimals than the scale, or does not fit a 64-bit integer
     */
    public static function toMinor(int|float|string $amount, int $scale): int
    {
        $factor = self::factor($scale);

        if (is_int($amount)) {
            if ($amount > intdiv(PHP_INT_MAX, $factor) || $amount < intdiv(PHP_INT_MIN, $factor)) {
                throw InvalidAmount::tooLarge((string) $amount);
            }

            return $amount * $factor;
        }

        if (is_float($amount)) {
            if (is_nan($amount) || is_infinite($amount)) {
                throw InvalidAmount::notFinite($amount);
            }

            if (abs($amount) >= self::FLOAT_EXACT_LIMIT / $factor) {
                throw InvalidAmount::floatTooLarge($amount, $scale);
            }

            $amount = self::shortest($amount);
        }

        return self::parse($amount, $scale);
    }

    /**
     * Minor units to a decimal string with exactly $scale decimals:
     * (25050, 2) is "250.50", (-5, 2) is "-0.05", (0, 3) is "0.000".
     */
    public static function format(int $minor, int $scale): string
    {
        self::factor($scale);

        // Works on the digits, so PHP_INT_MIN needs no abs().
        return self::formatDigits($minor < 0, ltrim((string) $minor, '-'), $scale);
    }

    /**
     * $minuend - $subtrahend (both in minor units) as a decimal string,
     * exact even when the difference does not fit a 64-bit integer.
     */
    public static function difference(int $minuend, int $subtrahend, int $scale): string
    {
        self::factor($scale);

        $overflows = ($subtrahend < 0 && $minuend > PHP_INT_MAX + $subtrahend)
            || ($subtrahend > 0 && $minuend < PHP_INT_MIN + $subtrahend);

        if (! $overflows) {
            return self::format($minuend - $subtrahend, $scale);
        }

        // The signs differ, so the magnitudes add up.
        return self::formatDigits(
            $minuend < 0,
            self::addDigits(ltrim((string) $minuend, '-'), ltrim((string) $subtrahend, '-')),
            $scale,
        );
    }

    /**
     * @return int<1, max> 10 ** $scale
     */
    private static function factor(int $scale): int
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new InvalidArgumentException(sprintf('The scale must be between 0 and %d, got %d.', self::MAX_SCALE, $scale));
        }

        $factor = 1;

        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }

        return $factor;
    }

    /**
     * @throws InvalidAmount
     */
    private static function parse(string $amount, int $scale): int
    {
        if (preg_match(self::PATTERN, $amount) !== 1) {
            throw InvalidAmount::malformed($amount);
        }

        $negative = $amount[0] === '-';
        $parts = explode('.', ltrim($amount, '-'), 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        if (strlen($fraction) > $scale) {
            // Trailing zeros are harmless ("100.500" at scale 2); any other
            // extra digit would have to be rounded away.
            if (trim(substr($fraction, $scale), '0') !== '') {
                throw InvalidAmount::tooPrecise($amount, $scale);
            }

            $fraction = substr($fraction, 0, $scale);
        }

        $digits = ltrim($whole.str_pad($fraction, $scale, '0'), '0');

        if ($digits === '') {
            return 0;
        }

        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw InvalidAmount::tooLarge($amount);
        }

        if (! $negative) {
            return (int) $digits;
        }

        return $digits === $limit ? PHP_INT_MIN : -(int) $digits;
    }

    /**
     * The shortest decimal string that reads back as exactly $value, in
     * plain (non-exponent) notation.
     *
     * Independent of the precision and serialize_precision ini settings:
     * sprintf() with an explicit precision ignores both, "%e" always uses
     * "." whatever the locale, and the string to float cast is
     * locale-independent since PHP 8. Trying 1 to 17 significant digits
     * and keeping the first that round-trips gives the same digits as
     * var_export() with serialize_precision = -1; 17 always round-trips.
     */
    private static function shortest(float $value): string
    {
        $candidate = sprintf('%.16e', $value);

        for ($precision = 0; $precision < 16; $precision++) {
            $attempt = sprintf('%.'.$precision.'e', $value);

            if ((float) $attempt === $value) {
                $candidate = $attempt;

                break;
            }
        }

        // "-1.005e+2": sign, digits, and where the decimal point goes.
        [$mantissa, $exponent] = explode('e', $candidate, 2);
        $negative = str_starts_with($mantissa, '-');
        $digits = str_replace(['-', '.'], '', $mantissa);
        $point = 1 + (int) $exponent;

        if ($point <= 0) {
            $plain = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $plain = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $plain = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return ($negative ? '-' : '').$plain;
    }

    /**
     * @param  string  $digits  the magnitude in minor units, digits only
     */
    private static function formatDigits(bool $negative, string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0');
        $sign = $negative && $digits !== '' ? '-' : '';

        if ($scale === 0) {
            return $sign.($digits === '' ? '0' : $digits);
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /**
     * The sum of two non-negative integers written as digit strings.
     */
    private static function addDigits(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $carry = 0;
        $sum = '';

        for ($i = 0, $length = max(strlen($left), strlen($right)); $i < $length; $i++) {
            $digit = (int) ($left[$i] ?? '0') + (int) ($right[$i] ?? '0') + $carry;
            $sum .= (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }

        return strrev($carry > 0 ? $sum.'1' : $sum);
    }
}
