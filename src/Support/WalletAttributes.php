<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Anabil\Wallet\Exceptions\InvalidWalletAttribute;

/**
 * Validation rules for the attributes fixed at wallet creation. Shared by
 * the HasWallet API and Wallet::performInsert() so every creation path,
 * including relation-based ones, enforces the same rules.
 */
final class WalletAttributes
{
    public const MAX_NAME_LENGTH = 64;

    public static function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw InvalidWalletAttribute::emptyName();
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidWalletAttribute::nameTooLong($name, self::MAX_NAME_LENGTH);
        }
    }

    public static function assertCurrency(string $currency): void
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw InvalidWalletAttribute::invalidCurrency($currency);
        }
    }
}
