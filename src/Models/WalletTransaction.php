<?php

declare(strict_types=1);

namespace Anabil\Wallet\Models;

use Anabil\Wallet\Database\WalletTransactionBuilder;
use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Support\Models;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int $id
 * @property int $wallet_id
 * @property string $type
 * @property int $amount
 * @property int $balance_after
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
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

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
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
}
