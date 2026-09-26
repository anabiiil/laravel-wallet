<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Contracts\WalletOperation;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Support\Types;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * The validated, descriptive part of a ledger entry: everything except the
 * wallet and the amount. Build it with Details::from(), which applies the
 * same rules as deposit() / withdraw().
 */
final readonly class Details
{
    public const MAX_DESCRIPTION_LENGTH = 255;

    /**
     * @param  string  $type  the value stored in the type column
     * @param  array<array-key, mixed>  $meta
     */
    public function __construct(
        public string $type,
        public ?Model $reference = null,
        public ?string $description = null,
        public array $meta = [],
        public ?Model $performedBy = null,
    ) {}

    /**
     * Validates the arguments given to deposit() / withdraw().
     *
     * When $type is a WalletOperation, the reference, description and meta
     * come from it and must not also be passed; the actor ($performedBy) is
     * call-site context and may be. A null type falls back to $default. A
     * blank description is stored as null.
     *
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidTransaction
     */
    public static function from(
        string|BackedEnum|WalletOperation|null $type,
        CoreType $default,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
    ): self {
        if ($type instanceof WalletOperation) {
            if ($reference !== null || $description !== null || $meta !== []) {
                throw InvalidTransaction::ambiguousOperation($type);
            }

            $reference = $type->reference();
            $description = $type->description();
            $meta = $type->meta();
            $type = $type->type();
        }

        if ($description !== null && trim($description) === '') {
            $description = null;
        }

        if ($description !== null && ($length = mb_strlen($description)) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidTransaction::descriptionTooLong($length, self::MAX_DESCRIPTION_LENGTH);
        }

        try {
            json_encode($meta, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidTransaction::invalidMeta($e);
        }

        self::assertPersisted('reference', $reference);
        self::assertPersisted('performedBy', $performedBy);

        return new self(
            Types::resolveForWrite($type ?? $default),
            $reference,
            $description,
            $meta,
            $performedBy,
        );
    }

    private static function assertPersisted(string $role, ?Model $model): void
    {
        if ($model !== null && (! $model->exists || $model->getKey() === null)) {
            throw InvalidTransaction::unsavedModel($role, $model);
        }
    }
}
