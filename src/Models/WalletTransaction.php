<?php

declare(strict_types=1);

namespace Anabil\Wallet\Models;

use Anabil\Wallet\Contracts\TransactionType;
use Anabil\Wallet\Database\WalletTransactionBuilder;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\Types;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A ledger entry. Read-only through Eloquent: the model can never insert,
 * update or delete itself, and WalletTransactionBuilder refuses every
 * query-level write. The checks live in the persistence internals, not in
 * model events, so quiet saves and Model::withoutEvents() are covered too.
 *
 * The type attribute reads as a CoreType case, a case of the enum
 * configured in "wallet.types", or the raw string when neither knows the
 * stored value; getRawOriginal('type') always returns the stored string.
 *
 * amount and balance_after are stored as integers in minor units and read
 * as decimal strings with the wallet's scale ("-30.00"); amountInMinor() and
 * balanceAfterInMinor() return the stored integers. The scale comes from
 * the wallet relation, which is therefore always eager loaded ($with); the
 * entries the package returns, and those read through
 * $wallet->transactions(), already carry their wallet.
 *
 * @property int $id
 * @property int $wallet_id
 * @property-read TransactionType|string $type
 * @property-read string $amount
 * @property-read string $balance_after
 * @property string|null $reference_type
 * @property int|string|null $reference_id
 * @property string|null $performed_by_type
 * @property int|string|null $performed_by_id
 * @property string|null $description
 * @property array<array-key, mixed>|null $meta
 * @property string|null $idempotency_key
 * @property string|null $idempotency_hash
 * @property string|null $group_uuid
 * @property int|null $reverses_id
 * @property Carbon|null $created_at
 */
class WalletTransaction extends Model
{
    /**
     * Ledger rows are append-only, so there is no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The wallet holds the scale the amounts are read with.
     *
     * @var list<string>
     */
    protected $with = ['wallet'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? (string) config('wallet.tables.transactions', 'wallet_transactions');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Models::wallet(), 'wallet_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function performedBy(): MorphTo
    {
        return $this->morphTo('performedBy', 'performed_by_type', 'performed_by_id');
    }

    /**
     * The entry this one reverses, if it is a reversal.
     *
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Models::transaction(), 'reverses_id');
    }

    /**
     * The entry that reverses this one, if it has been reversed.
     *
     * @return HasOne<WalletTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Models::transaction(), 'reverses_id');
    }

    /**
     * Read-only: there is no setter, so the stored string is never rewritten
     * (and the model never saves anyway).
     *
     * @return Attribute<TransactionType|string, never>
     */
    protected function type(): Attribute
    {
        return Attribute::get(
            fn (mixed $value): TransactionType|string => Types::resolveForRead((string) $value),
        );
    }

    /**
     * Read-only, as a decimal string with the wallet's scale: negative for a
     * debit.
     *
     * @return Attribute<string, never>
     */
    protected function amount(): Attribute
    {
        return Attribute::get(fn (mixed $value): string => Amount::format(self::rawMinor($value), $this->scale()));
    }

    /**
     * Read-only, as a decimal string with the wallet's scale.
     *
     * @return Attribute<string, never>
     */
    protected function balanceAfter(): Attribute
    {
        return Attribute::get(fn (mixed $value): string => Amount::format(self::rawMinor($value), $this->scale()));
    }

    /**
     * The amount as stored: a signed integer in minor units.
     */
    public function amountInMinor(): int
    {
        return self::rawMinor($this->attributes['amount'] ?? 0);
    }

    /**
     * The balance after this entry as stored: an integer in minor units.
     */
    public function balanceAfterInMinor(): int
    {
        return self::rawMinor($this->attributes['balance_after'] ?? 0);
    }

    /**
     * The other entry of the same operation (for a transfer: the
     * recipient's entry of a transfer_out, the sender's of a transfer_in),
     * or null when this entry is not part of a group. Read on this entry's
     * connection; not cached.
     */
    public function counterpart(): ?WalletTransaction
    {
        $group = $this->getAttribute('group_uuid');

        if ($group === null || $group === '') {
            return null;
        }

        $query = $this->newQuery()
            ->where('group_uuid', $group)
            ->whereKeyNot($this->getKey())
            ->orderBy($this->getKeyName());

        // A transfer leg's counterpart is the opposite transfer leg, even
        // once other entries (e.g. fees) share the group.
        $counterType = match ($this->getRawOriginal('type') ?? $this->getAttributes()['type'] ?? null) {
            CoreType::TransferOut->value => CoreType::TransferIn,
            CoreType::TransferIn->value => CoreType::TransferOut,
            default => null,
        };

        if ($counterType !== null) {
            $query->ofType($counterType);
        }

        return $query->first();
    }

    public function isCredit(): bool
    {
        return $this->amountInMinor() > 0;
    }

    public function isDebit(): bool
    {
        return $this->amountInMinor() < 0;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return WalletTransactionBuilder<*>
     */
    public function newEloquentBuilder($query): WalletTransactionBuilder
    {
        return new WalletTransactionBuilder($query);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): never
    {
        throw ImmutableTransaction::cannotCreate();
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw ImmutableTransaction::cannotUpdate($this);
    }

    protected function performDeleteOnModel(): never
    {
        throw ImmutableTransaction::cannotDelete($this);
    }

    /**
     * The scale of the entry's wallet (loaded lazily if it is not yet).
     *
     * @throws WalletException when the wallet cannot be found
     */
    private function scale(): int
    {
        $wallet = $this->getRelationValue('wallet');

        if (! $wallet instanceof Wallet) {
            throw new WalletException(sprintf(
                'Cannot read the amounts of wallet transaction [%s]: its wallet [%s] was not found, and amounts are formatted with its scale.',
                (string) $this->getKey(),
                (string) ($this->attributes['wallet_id'] ?? ''),
            ));
        }

        return $wallet->scale();
    }

    /**
     * A stored integer column as an int, whatever type the driver returned.
     */
    private static function rawMinor(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
