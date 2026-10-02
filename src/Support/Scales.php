<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Illuminate\Support\Facades\Config;

/**
 * The number of decimal places a new wallet gets for its currency, from
 * "wallet.scale" and "wallet.currency_scales". Only read when a wallet is
 * created (and to format the zero balance of a wallet that does not exist
 * yet): an existing wallet always uses its own scale column.
 *
 * The configuration is read and checked on every call, so there is no
 * cached state to go stale when the config changes (e.g. in tests).
 */
final class Scales
{
    /**
     * @var list<int>
     */
    public const ALLOWED = [2, 3];

    /**
     * @throws InvalidConfiguration when a configured scale is not 2 or 3
     */
    public static function forCurrency(string $currency): int
    {
        $scale = self::validated(Config::get('wallet.scale', 2), 'wallet.scale');
        $map = Config::get('wallet.currency_scales', []);

        if (! is_array($map)) {
            throw InvalidConfiguration::currencyScalesNotArray($map);
        }

        // Every entry is checked, not only the one asked for, so a typo is
        // reported on the first wallet created rather than much later.
        foreach ($map as $code => $value) {
            $value = self::validated($value, "wallet.currency_scales.{$code}");

            if ($code === $currency) {
                $scale = $value;
            }
        }

        return $scale;
    }

    public static function isAllowed(int $scale): bool
    {
        return in_array($scale, self::ALLOWED, true);
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function validated(mixed $value, string $key): int
    {
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || ! self::isAllowed($value)) {
            throw InvalidConfiguration::invalidScale($key, $value, self::ALLOWED);
        }

        return $value;
    }
}
