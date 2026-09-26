<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

class InvalidWalletAttribute extends WalletException
{
    public static function emptyName(): self
    {
        return new self('A wallet name must not be empty.');
    }

    public static function nameTooLong(string $name, int $max): self
    {
        return new self(sprintf(
            'Wallet name [%s] is too long: at most %d characters are allowed.',
            $name,
            $max,
        ));
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self(sprintf(
            'Invalid currency [%s]: expected a 3-letter uppercase ISO 4217 code such as "EGP" or "USD". Lowercase codes are rejected, not converted.',
            $currency,
        ));
    }

    public static function holderNotPersisted(string $holderType): self
    {
        return new self(sprintf(
            'Cannot use wallets of an unsaved [%s]: save the holder model first.',
            $holderType,
        ));
    }
}
