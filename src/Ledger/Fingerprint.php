<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * The fingerprint stored in idempotency_hash: a SHA-256 of everything that
 * defines a ledger operation, so a retry with the same idempotency key can
 * be told apart from a different operation that reuses the key.
 *
 * The payload is versioned. Changing what goes into it (or how it is
 * encoded) changes every hash, so a retry of a request made before the
 * change would be reported as a conflict: bump VERSION when doing so.
 *
 * @internal
 */
final class Fingerprint
{
    public const VERSION = 1;

    /**
     * @param  int  $delta  the signed amount in minor units (positive credit, negative debit), so 100.5, 100.50 and "100.50" fingerprint the same
     * @param  array<array-key, mixed>  $context  extra operation data, e.g. the counterparty of a transfer
     * @return non-empty-string 64 lowercase hex characters
     */
    public static function for(int $delta, Details $details, array $context = []): string
    {
        $payload = [
            'v' => self::VERSION,
            'direction' => $delta < 0 ? 'debit' : 'credit',
            // As a string: abs() of the smallest integer would be a float.
            'amount' => ltrim((string) $delta, '-'),
            'type' => $details->type,
            'reference' => self::morph($details->reference),
            'performed_by' => self::morph($details->performedBy),
            'description' => $details->description,
            'meta' => self::canonical($details->meta),
            'context' => self::canonical($context),
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * The morph type and key of a model, the key as a string so that 5 and
     * "5" fingerprint the same.
     *
     * @return array{type: string, id: string}|null
     */
    private static function morph(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $key = $model->getKey();

        return [
            'type' => $model->getMorphClass(),
            'id' => is_scalar($key) || $key instanceof Stringable ? (string) $key : '',
        ];
    }

    /**
     * Sorts associative arrays by key, recursively, so key order does not
     * matter. Lists keep their order: it is part of the data.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
