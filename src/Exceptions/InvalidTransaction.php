<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Contracts\WalletOperation;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Thrown when the details of a deposit / withdrawal (type, reference,
 * description, meta, actor) are not valid. Nothing is written.
 */
class InvalidTransaction extends WalletException
{
    public static function ambiguousOperation(WalletOperation $operation): self
    {
        return new self(sprintf(
            'The operation [%s] already provides the reference, description and meta: do not pass them alongside it. Only performedBy may be given with an operation.',
            $operation::class,
        ));
    }

    public static function descriptionTooLong(int $length, int $max): self
    {
        return new self(sprintf(
            'The transaction description is %d characters long: at most %d are allowed. Put longer data in meta.',
            $length,
            $max,
        ));
    }

    public static function invalidMeta(Throwable $previous): self
    {
        return new self(sprintf(
            'The transaction meta cannot be stored as JSON: %s. Only pass arrays of strings, numbers, booleans, null and nested arrays.',
            $previous->getMessage(),
        ), 0, $previous);
    }

    public static function unsavedModel(string $role, Model $model): self
    {
        return new self(sprintf(
            'The %s model [%s] must be saved before it is attached to a transaction.',
            $role,
            $model::class,
        ));
    }

    public static function invalidTypeFormat(string $type, string $pattern): self
    {
        return new self(sprintf(
            'Invalid transaction type [%s]: types must match %s (a lowercase letter first, at most 32 characters).',
            $type,
            $pattern,
        ));
    }

    public static function unknownType(string $type, string $enum): self
    {
        return new self(sprintf(
            'Unknown transaction type [%s]: it is neither a core type nor a value of the configured enum [%s]. Add a case to the enum or use one of its cases.',
            $type,
            $enum,
        ));
    }

    public static function unsupportedEnum(BackedEnum $type, ?string $configured): self
    {
        return new self(sprintf(
            'Transaction type [%s::%s] is not supported: use a CoreType case%s.',
            $type::class,
            $type->name,
            $configured === null
                ? ', a plain string, or configure your enum in [wallet.types]'
                : sprintf(' or a case of the configured enum [%s]', $configured),
        ));
    }

    public static function reservedType(string $type): self
    {
        return new self(sprintf(
            'The transaction type [%s] is reserved: the package writes it itself for transfers and reversals. Use the dedicated API instead of deposit() / withdraw().',
            $type,
        ));
    }
}
