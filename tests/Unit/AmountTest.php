<?php

declare(strict_types=1);

use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Support\Amount;

/*
 * Pure conversion tests: no application or database is booted.
 */

it('converts ints by the scale', function (int $amount, int $scale, int $minor): void {
    expect(Amount::toMinor($amount, $scale))->toBe($minor);
})->with([
    [250, 2, 250_00],
    [0, 2, 0],
    [-5, 3, -5_000],
    [intdiv(PHP_INT_MAX, 100), 2, intdiv(PHP_INT_MAX, 100) * 100],
    [intdiv(PHP_INT_MIN, 1000), 3, intdiv(PHP_INT_MIN, 1000) * 1000],
]);

it('refuses ints that overflow once scaled', function (int $amount, int $scale): void {
    expect(fn () => Amount::toMinor($amount, $scale))->toThrow(InvalidAmount::class, 'too large');
})->with([
    [intdiv(PHP_INT_MAX, 100) + 1, 2],
    [intdiv(PHP_INT_MIN, 100) - 1, 2],
    [PHP_INT_MAX, 3],
    [PHP_INT_MIN, 2],
]);

it('converts decimal strings exactly', function (string $amount, int $scale, int $minor): void {
    expect(Amount::toMinor($amount, $scale))->toBe($minor);
})->with([
    ['100.50', 2, 100_50],
    ['100.5', 2, 100_50],
    ['100.500', 2, 100_50],
    ['100.50000000000000000000', 2, 100_50],
    ['100', 2, 100_00],
    ['0.07', 2, 7],
    ['007.50', 2, 7_50],
    ['-19.99', 2, -19_99],
    ['10.125', 3, 10_125],
    ['10.1', 3, 10_100],
    ['0', 2, 0],
    ['-0', 2, 0],
    ['-0.00', 3, 0],
    ['92233720368547758.07', 2, PHP_INT_MAX],
    ['-92233720368547758.08', 2, PHP_INT_MIN],
    ['9223372036854775.807', 3, PHP_INT_MAX],
    ['-9223372036854775.808', 3, PHP_INT_MIN],
    ['000000000000000000000000000001.00', 2, 1_00],
]);

it('refuses malformed strings', function (string $amount): void {
    expect(fn () => Amount::toMinor($amount, 2))->toThrow(InvalidAmount::class, 'plain decimal string');
})->with([
    'thousands separator' => ['1,000'],
    'leading space' => [' 10'],
    'trailing space' => ['10 '],
    'trailing newline' => ["10\n"],
    'exponent' => ['1e3'],
    'upper exponent' => ['1.5E2'],
    'plus sign' => ['+5'],
    'leading dot' => ['.5'],
    'trailing dot' => ['5.'],
    'comma decimal' => ['5,50'],
    'letters' => ['abc'],
    'empty' => [''],
    'only minus' => ['-'],
    'double minus' => ['--1'],
    'two dots' => ['1.2.3'],
    'hex' => ['0x1A'],
    'non-ascii digits' => ['١٠'],
    'INF' => ['INF'],
    'NAN' => ['NAN'],
]);

it('never rounds a string with more decimals than the scale', function (string $amount, int $scale): void {
    expect(fn () => Amount::toMinor($amount, $scale))->toThrow(InvalidAmount::class, 'never rounded');
})->with([
    ['10.005', 2],
    ['0.001', 2],
    ['-0.001', 2],
    ['100.501', 2],
    ['10.0001', 3],
    ['1.0000000000000000000001', 2],
]);

it('refuses strings beyond the 64-bit range', function (string $amount, int $scale): void {
    expect(fn () => Amount::toMinor($amount, $scale))->toThrow(InvalidAmount::class, 'too large');
})->with([
    ['92233720368547758.08', 2],
    ['-92233720368547758.09', 2],
    ['9223372036854775.808', 3],
    ['99999999999999999999', 2],
    [str_repeat('9', 200), 2],
]);

it('converts floats through their shortest decimal form', function (float $amount, int $scale, int $minor): void {
    expect(Amount::toMinor($amount, $scale))->toBe($minor);
})->with([
    [100.50, 2, 100_50],
    [100.5, 2, 100_50],
    [19.99, 2, 19_99],
    [0.07, 2, 7],
    [1234567.89, 2, 1234567_89],
    [-1234567.89, 2, -1234567_89],
    [10.125, 3, 10_125],
    [0.1, 3, 100],
    [1.0E+13, 2, 1_000_000_000_000_000],
    [-0.0, 2, 0],
    [0.0, 3, 0],
]);

it('refuses a float whose shortest form has more decimals than the scale', function (float $amount, int $scale): void {
    expect(fn () => Amount::toMinor($amount, $scale))->toThrow(InvalidAmount::class, 'never rounded');
})->with([
    '0.1 + 0.2' => [0.1 + 0.2, 2],
    '10.005' => [10.005, 2],
    'exponent form 1.0E-5' => [1.0E-5, 3],
    'tiny' => [5.0E-324, 2],
]);

it('names the digits it read in the error', function (): void {
    expect(fn () => Amount::toMinor(0.1 + 0.2, 2))->toThrow(InvalidAmount::class, '[0.30000000000000004]');
});

it('refuses NAN and INF', function (float $amount): void {
    expect(fn () => Amount::toMinor($amount, 2))->toThrow(InvalidAmount::class, 'NAN and INF');
})->with([NAN, INF, -INF]);

it('refuses floats too large to hold the decimals and suggests a string', function (float $amount, int $scale): void {
    expect(fn () => Amount::toMinor($amount, $scale))->toThrow(InvalidAmount::class, 'Pass the amount as a string');
})->with([
    [2 ** 53 / 100, 2],
    [-(2 ** 53 / 100), 2],
    [2 ** 53 / 1000, 3],
    [1.0E+20, 2],
]);

it('reads floats the same whatever the precision ini settings', function (): void {
    $precision = ini_get('precision');
    $serialize = ini_get('serialize_precision');

    try {
        ini_set('precision', '17');
        ini_set('serialize_precision', '17');

        expect(Amount::toMinor(0.1, 2))->toBe(10)
            ->and(Amount::toMinor(19.99, 2))->toBe(19_99)
            ->and(fn () => Amount::toMinor(0.1 + 0.2, 2))->toThrow(InvalidAmount::class);

        ini_set('precision', '5');
        ini_set('serialize_precision', '5');

        expect(Amount::toMinor(1234567.89, 2))->toBe(1234567_89);
    } finally {
        ini_set('precision', (string) $precision);
        ini_set('serialize_precision', (string) $serialize);
    }
});

it('formats minor units with exactly the scale', function (int $minor, int $scale, string $expected): void {
    expect(Amount::format($minor, $scale))->toBe($expected);
})->with([
    [250_50, 2, '250.50'],
    [250_00, 2, '250.00'],
    [-5, 2, '-0.05'],
    [-100, 2, '-1.00'],
    [0, 2, '0.00'],
    [0, 3, '0.000'],
    [5, 3, '0.005'],
    [10_125, 3, '10.125'],
    [PHP_INT_MAX, 2, '92233720368547758.07'],
    [PHP_INT_MIN, 2, '-92233720368547758.08'],
    [PHP_INT_MIN, 3, '-9223372036854775.808'],
]);

it('subtracts exactly, even past the 64-bit range', function (int $minuend, int $subtrahend, int $scale, string $expected): void {
    expect(Amount::difference($minuend, $subtrahend, $scale))->toBe($expected);
})->with([
    [10, 3, 2, '0.07'],
    [3, 10, 2, '-0.07'],
    [50_00, -10_00, 2, '60.00'],
    [PHP_INT_MAX, -1, 2, '92233720368547758.08'],
    [PHP_INT_MAX, PHP_INT_MIN, 2, '184467440737095516.15'],
    [PHP_INT_MIN, PHP_INT_MAX, 2, '-184467440737095516.15'],
    [PHP_INT_MIN, 1, 3, '-9223372036854775.809'],
]);

it('refuses a scale outside 0 to 18', function (int $scale): void {
    expect(fn () => Amount::format(1, $scale))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Amount::toMinor(1, $scale))->toThrow(InvalidArgumentException::class);
})->with([-1, 19]);

it('round-trips any integer through format() and toMinor()', function (int $scale): void {
    $samples = [0, 1, -1, 9, 10, 99, 100, -100, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1];

    for ($i = 0; $i < 5_000; $i++) {
        $samples[] = random_int(PHP_INT_MIN, PHP_INT_MAX);
        $samples[] = random_int(-1_000_000, 1_000_000);
    }

    foreach ($samples as $minor) {
        expect(Amount::toMinor(Amount::format($minor, $scale), $scale))->toBe($minor);
    }
})->with([2, 3]);

it('round-trips floats of up to 15 significant digits', function (int $scale): void {
    for ($i = 0; $i < 5_000; $i++) {
        $minor = random_int(-999_999_999_999_999, 999_999_999_999_999);

        // The float a caller would write for this amount, e.g. 1234.56.
        $float = (float) Amount::format($minor, $scale);

        expect(Amount::toMinor($float, $scale))->toBe($minor);
    }
})->with([2, 3]);
