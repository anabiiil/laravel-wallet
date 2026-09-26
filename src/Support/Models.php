<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;

/**
 * Resolves the model classes configured under wallet.models, making sure
 * they extend the package models so the protections are always inherited.
 */
final class Models
{
    /**
     * @return class-string<Wallet>
     */
    public static function wallet(): string
    {
        return self::resolve('wallet', Wallet::class);
    }

    /**
     * @return class-string<WalletTransaction>
     */
    public static function transaction(): string
    {
        return self::resolve('transaction', WalletTransaction::class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private static function resolve(string $key, string $base): string
    {
        $class = config("wallet.models.{$key}", $base);

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new WalletException(sprintf(
                'The model configured in [wallet.models.%s] must be a class extending [%s], got [%s].',
                $key,
                $base,
                is_string($class) ? $class : get_debug_type($class),
            ));
        }

        return $class;
    }
}
