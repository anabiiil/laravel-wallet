<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;

/**
 * Adds a polymorphic pair of columns ({name}_type / {name}_id) to a table
 * builder, sized according to the key type configured for that relation in
 * config/wallet.php ("int" | "uuid" | "ulid"). Shared by the wallets and
 * wallet_transactions migrations so their morph columns can't drift apart.
 * Adds no index — callers add whatever index, unique constraint, or foreign
 * key fits their table.
 */
final class MorphKey
{
    public static function columns(Blueprint $table, string $name, string $configKey, bool $nullable): void
    {
        $type = config("wallet.morph_keys.{$configKey}", 'int');

        $typeColumn = $table->string("{$name}_type");

        $idColumn = match ($type) {
            'int' => $table->unsignedBigInteger("{$name}_id"),
            'uuid' => $table->uuid("{$name}_id"),
            'ulid' => $table->ulid("{$name}_id"),
            default => throw new InvalidArgumentException(sprintf(
                'Invalid morph key type [%s] for config key [wallet.morph_keys.%s]. Expected one of: int, uuid, ulid.',
                var_export($type, true),
                $configKey,
            )),
        };

        if ($nullable) {
            $typeColumn->nullable();
            $idColumn->nullable();
        }
    }
}
