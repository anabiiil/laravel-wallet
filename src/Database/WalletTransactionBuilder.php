<?php

declare(strict_types=1);

namespace Anabil\Wallet\Database;

use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Types;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query builder for ledger entries: reads only. Every write is refused,
 * including the insert* methods Eloquent would otherwise forward straight to
 * the base query builder through its __call() "passthru".
 *
 * Deliberately NOT covered: toBase(), getQuery(), DB::table() and raw SQL.
 * Those are the plain query builder and sit outside Eloquent; the package's
 * own ledger writer uses them.
 *
 * It also carries the ledger's query helpers (credits(), debits(),
 * ofType()...). They are builder methods rather than model scopes, and none
 * shares a name with a model relation, so they work the same from
 * WalletTransaction::query() and from $wallet->transactions().
 *
 * @template TModel of WalletTransaction
 *
 * @extends Builder<TModel>
 */
class WalletTransactionBuilder extends Builder
{
    /**
     * Entries that added money (positive amount).
     */
    public function credits(): static
    {
        return $this->where($this->qualifyColumn('amount'), '>', 0);
    }

    /**
     * Entries that took money out (negative amount).
     */
    public function debits(): static
    {
        return $this->where($this->qualifyColumn('amount'), '<', 0);
    }

    /**
     * Entries of any of the given types. Enum cases must be CoreType or the
     * configured enum; strings are matched as stored, so reserved and
     * retired types can be queried too.
     */
    public function ofType(string|BackedEnum ...$types): static
    {
        return $this->whereIn(
            $this->qualifyColumn('type'),
            array_values(array_unique(array_map(Types::resolveForQuery(...), $types))),
        );
    }

    /**
     * Entries whose reference is the given model.
     */
    public function whereReference(Model $model): static
    {
        return $this->whereMorphedTo('reference', $model);
    }

    /**
     * Entries performed by the given model.
     */
    public function wherePerformedBy(Model $model): static
    {
        return $this->whereMorphedTo('performedBy', $model);
    }

    /**
     * Newest entries first. Ordered by id, the ledger's stable order;
     * created_at can tie between entries.
     */
    public function latestFirst(): static
    {
        return $this->orderByDesc($this->qualifyColumn($this->getModel()->getKeyName()));
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function create(array $attributes = []): never
    {
        throw ImmutableTransaction::cannotCreate();
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function createQuietly(array $attributes = []): never
    {
        throw ImmutableTransaction::cannotCreate();
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function forceCreate(array $attributes): never
    {
        throw ImmutableTransaction::cannotCreate();
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function forceCreateQuietly(array $attributes = []): never
    {
        throw ImmutableTransaction::cannotCreate();
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): never
    {
        throw ImmutableTransaction::cannotBulkWrite('insert');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): never
    {
        throw ImmutableTransaction::cannotBulkWrite('insertGetId');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): never
    {
        throw ImmutableTransaction::cannotBulkWrite('insertOrIgnore');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): never
    {
        throw ImmutableTransaction::cannotBulkWrite('insertUsing');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw ImmutableTransaction::cannotBulkWrite('insertOrIgnoreUsing');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw ImmutableTransaction::cannotBulkWrite('upsert');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw ImmutableTransaction::cannotBulkWrite('updateOrInsert');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function update(array $values): never
    {
        throw ImmutableTransaction::cannotBulkWrite('update');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function updateFrom(array $values): never
    {
        throw ImmutableTransaction::cannotBulkWrite('updateFrom');
    }

    /**
     * @param  mixed  $column
     * @param  float|int  $amount
     * @param  array<array-key, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw ImmutableTransaction::cannotBulkWrite('increment');
    }

    /**
     * @param  mixed  $column
     * @param  float|int  $amount
     * @param  array<array-key, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw ImmutableTransaction::cannotBulkWrite('decrement');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): never
    {
        throw ImmutableTransaction::cannotBulkWrite('incrementEach');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): never
    {
        throw ImmutableTransaction::cannotBulkWrite('decrementEach');
    }

    /**
     * @param  mixed  $column
     */
    public function touch($column = null): never
    {
        throw ImmutableTransaction::cannotBulkWrite('touch');
    }

    public function delete(): never
    {
        throw ImmutableTransaction::cannotBulkWrite('delete');
    }

    public function forceDelete(): never
    {
        throw ImmutableTransaction::cannotBulkWrite('forceDelete');
    }

    public function truncate(): never
    {
        throw ImmutableTransaction::cannotBulkWrite('truncate');
    }
}
