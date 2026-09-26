<?php

declare(strict_types=1);

namespace Anabil\Wallet\Database;

use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query builder for ledger entries: reads only. Every write is refused,
 * including the insert* methods Eloquent would otherwise forward straight to
 * the base query builder through its __call() "passthru".
 *
 * Deliberately NOT covered: toBase(), getQuery(), DB::table() and raw SQL.
 * Those are the plain query builder and sit outside Eloquent; the package's
 * own ledger writer uses them.
 *
 * @template TModel of WalletTransaction
 *
 * @extends Builder<TModel>
 */
class WalletTransactionBuilder extends Builder
{
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
