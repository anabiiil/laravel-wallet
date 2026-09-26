<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Contracts\TransactionType;

class InvalidConfiguration extends WalletException
{
    public static function typesNotStringEnum(mixed $value): self
    {
        return new self(sprintf(
            'The [wallet.types] config must be null or the class name of a string-backed enum implementing [%s], got [%s].',
            TransactionType::class,
            is_string($value) ? $value : get_debug_type($value),
        ));
    }

    public static function typesNotTransactionType(string $class): self
    {
        return new self(sprintf(
            'The enum [%s] configured in [wallet.types] must implement [%s].',
            $class,
            TransactionType::class,
        ));
    }

    public static function typesCollideWithCore(string $class, string $value): self
    {
        return new self(sprintf(
            'The enum [%s] configured in [wallet.types] defines the value [%s], which is a core transaction type. Use CoreType for it or rename your case\'s value.',
            $class,
            $value,
        ));
    }

    public static function typeValueInvalid(string $class, string $value, string $pattern): self
    {
        return new self(sprintf(
            'The enum [%s] configured in [wallet.types] defines the value [%s], which is not a valid transaction type: values must match %s (at most 32 characters, lowercase).',
            $class,
            $value,
            $pattern,
        ));
    }
}
