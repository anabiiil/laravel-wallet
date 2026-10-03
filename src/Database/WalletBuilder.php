<?php

declare(strict_types=1);

namespace Anabil\Wallet\Database;

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Models\Wallet;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Query builder for wallets. Reads are untouched and harmless columns (meta,
 * timestamps) can still be updated in bulk, but any query-level write to a
 * protected column (status included) or to min_balance, any bulk
 * insert/upsert and any delete is refused. min_balance is refused because
 * a query writes raw minor units while the rest of the API speaks major
 * units: change it with $wallet->setMinBalance(). The status only changes
 * through $wallet->freeze(), unfreeze() and close(), whose guarded UPDATE
 * keeps it consistent with the money (a closed wallet is always empty).
 * The model's own save() writes through the base query builder (see
 * Wallet::performUpdate()), so it is not affected.
 *
 * The insert* methods below would otherwise be forwarded by Eloquent's
 * __call() "passthru" straight to the base query builder, bypassing the
 * model entirely. Wallet::insertAndSetId() inserts through toBase() so the
 * model's own save path keeps working.
 *
 * Deliberately NOT covered: toBase(), getQuery(), DB::table() and raw SQL.
 * Those are the plain query builder and sit outside Eloquent.
 *
 * @template TModel of Wallet
 *
 * @extends Builder<TModel>
 */
class WalletBuilder extends Builder
{
    /**
     * Only active wallets.
     *
     * @return $this
     */
    public function active(): static
    {
        return $this->whereStatus(WalletStatus::Active);
    }

    /**
     * Only frozen wallets.
     *
     * @return $this
     */
    public function frozen(): static
    {
        return $this->whereStatus(WalletStatus::Frozen);
    }

    /**
     * Only closed wallets.
     *
     * @return $this
     */
    public function closed(): static
    {
        return $this->whereStatus(WalletStatus::Closed);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->guardColumns(array_keys($values), 'update');

        return parent::update($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        $this->guardColumns(array_keys($values), 'update');

        return $this->toBase()->updateFrom($values);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->guardColumns([$column, ...array_keys($extra)], 'increment');

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->guardColumns([$column, ...array_keys($extra)], 'decrement');

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->guardColumns([...array_keys($columns), ...array_keys($extra)], 'increment');

        return $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->guardColumns([...array_keys($columns), ...array_keys($extra)], 'decrement');

        return $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra));
    }

    /**
     * @param  array<array-key, mixed>|string|null  $column
     * @return int|false
     */
    public function touch($column = null)
    {
        if ($column !== null) {
            $this->guardColumns((array) $column, 'touch');
        }

        return parent::touch($column);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw ImmutableWallet::cannotBulkWrite('upsert');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): never
    {
        throw ImmutableWallet::cannotBulkWrite('insert');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): never
    {
        throw ImmutableWallet::cannotBulkWrite('insertGetId');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): never
    {
        throw ImmutableWallet::cannotBulkWrite('insertOrIgnore');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): never
    {
        throw ImmutableWallet::cannotBulkWrite('insertUsing');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw ImmutableWallet::cannotBulkWrite('insertOrIgnoreUsing');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw ImmutableWallet::cannotBulkWrite('updateOrInsert');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function forceCreate(array $attributes): never
    {
        throw ImmutableWallet::cannotBulkWrite('forceCreate');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function forceCreateQuietly(array $attributes = []): never
    {
        throw ImmutableWallet::cannotBulkWrite('forceCreateQuietly');
    }

    public function delete(): never
    {
        throw ImmutableWallet::cannotBulkWrite('delete');
    }

    public function forceDelete(): never
    {
        throw ImmutableWallet::cannotBulkWrite('forceDelete');
    }

    public function truncate(): never
    {
        throw ImmutableWallet::cannotBulkWrite('truncate');
    }

    /**
     * @return $this
     */
    private function whereStatus(WalletStatus $status): static
    {
        $this->where($this->qualifyColumn('status'), $status->value);

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $columns
     */
    private function guardColumns(array $columns, string $operation): void
    {
        $protected = $this->getModel()->protectedColumns();

        foreach ($columns as $column) {
            $name = $this->normaliseColumn($column);

            if (in_array($name, $protected, true)) {
                throw ImmutableWallet::protectedColumn($name, $operation);
            }

            if ($name === 'min_balance') {
                throw ImmutableWallet::minBalanceThroughQuery($operation);
            }
        }
    }

    /**
     * Reduces "wallets.balance", `wallets`.`balance`, "BALANCE" or a raw
     * expression to the bare, lowercase column name. JSON paths such as
     * "meta->foo" reduce to their column ("meta").
     */
    private function normaliseColumn(mixed $column): string
    {
        if ($column instanceof Expression) {
            $column = $column->getValue($this->getQuery()->getGrammar());
        }

        $column = Str::before((string) (is_scalar($column) ? $column : ''), '->');
        $column = Str::afterLast($column, '.');

        return strtolower(trim($column, " \t\n\r\0\x0B`\"[]"));
    }
}
