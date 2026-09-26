<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Anabil\Wallet\Contracts\TransactionType;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use BackedEnum;
use Illuminate\Support\Facades\Config;
use ReflectionEnum;
use ReflectionNamedType;

/**
 * Maps transaction types between what callers pass (CoreType cases, cases of
 * the enum configured in "wallet.types", or strings) and the string stored
 * in the ledger's type column.
 *
 * The configuration is read and checked on every call, so there is no
 * cached state to go stale when the config changes (e.g. in tests).
 */
final class Types
{
    /**
     * Stored type values: a lowercase letter, then up to 31 more characters,
     * so they always fit the 32-character type column.
     */
    public const PATTERN = '/^[a-z][a-z0-9_.:-]{0,31}$/D';

    /**
     * The stored value for a type given to deposit() / withdraw().
     *
     * @param  bool  $internal  true only for entries the package writes itself, which may use the reserved core types
     *
     * @throws InvalidTransaction when the type is unknown, badly formatted or reserved
     * @throws InvalidConfiguration when "wallet.types" is misconfigured
     */
    public static function resolveForWrite(string|BackedEnum $type, bool $internal = false): string
    {
        $enum = self::configuredEnum();

        if ($type instanceof BackedEnum) {
            $value = self::enumValue($type, $enum);
        } elseif ($enum !== null) {
            if (CoreType::tryFrom($type) === null && $enum::tryFrom($type) === null) {
                throw InvalidTransaction::unknownType($type, $enum);
            }

            $value = $type;
        } else {
            if (preg_match(self::PATTERN, $type) !== 1) {
                throw InvalidTransaction::invalidTypeFormat($type, self::PATTERN);
            }

            $value = $type;
        }

        if (! $internal && CoreType::tryFrom($value)?->isReserved() === true) {
            throw InvalidTransaction::reservedType($value);
        }

        return $value;
    }

    /**
     * The stored value to filter on. Strings are used as they are (a query
     * may look for any value, including reserved or retired ones); enum
     * cases must still be CoreType or the configured enum.
     */
    public static function resolveForQuery(string|BackedEnum $type): string
    {
        return $type instanceof BackedEnum
            ? self::enumValue($type, self::configuredEnum())
            : $type;
    }

    /**
     * The type object for a stored value: a CoreType case, a case of the
     * configured enum, or the raw string when neither knows the value.
     */
    public static function resolveForRead(string $value): TransactionType|string
    {
        if ($core = CoreType::tryFrom($value)) {
            return $core;
        }

        $enum = self::configuredEnum();

        return $enum === null ? $value : ($enum::tryFrom($value) ?? $value);
    }

    /**
     * The validated enum class configured in "wallet.types", or null.
     *
     * @return class-string<TransactionType&BackedEnum>|null
     *
     * @throws InvalidConfiguration
     */
    public static function configuredEnum(): ?string
    {
        $class = Config::get('wallet.types');

        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! enum_exists($class)) {
            throw InvalidConfiguration::typesNotStringEnum($class);
        }

        $backing = (new ReflectionEnum($class))->getBackingType();

        if (! $backing instanceof ReflectionNamedType || $backing->getName() !== 'string') {
            throw InvalidConfiguration::typesNotStringEnum($class);
        }

        if (! is_subclass_of($class, TransactionType::class) || ! is_subclass_of($class, BackedEnum::class)) {
            throw InvalidConfiguration::typesNotTransactionType($class);
        }

        foreach ($class::cases() as $case) {
            $value = (string) $case->value;

            if (CoreType::tryFrom($value) !== null) {
                throw InvalidConfiguration::typesCollideWithCore($class, $value);
            }

            if (preg_match(self::PATTERN, $value) !== 1) {
                throw InvalidConfiguration::typeValueInvalid($class, $value, self::PATTERN);
            }
        }

        return $class;
    }

    /**
     * @param  class-string<TransactionType&BackedEnum>|null  $enum
     */
    private static function enumValue(BackedEnum $type, ?string $enum): string
    {
        if ($type instanceof CoreType || ($enum !== null && $type instanceof $enum)) {
            return (string) $type->value;
        }

        throw InvalidTransaction::unsupportedEnum($type, $enum);
    }
}
