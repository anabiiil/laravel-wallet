<?php

declare(strict_types=1);

namespace Anabil\Wallet\Models;

use Anabil\Wallet\Contracts\WalletOperation;
use Anabil\Wallet\Database\WalletBuilder;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\LedgerWriter;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\WalletAttributes;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A holder's wallet. Its balance, holder, name and currency can never be
 * changed through Eloquent, and it can never be deleted: the checks live in
 * the model's persistence internals (performInsert / performUpdate /
 * performDeleteOnModel) and in WalletBuilder, not in model events, so they
 * also hold for saveQuietly(), createQuietly() and Model::withoutEvents().
 *
 * @property int $id
 * @property string $holder_type
 * @property int|string $holder_id
 * @property string $name
 * @property string $currency
 * @property int $balance
 * @property int $min_balance
 * @property WalletStatus $status
 * @property array<array-key, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Wallet extends Model
{
    /**
     * Columns that are fixed once the wallet exists. The primary key is
     * protected as well (see protectedColumns()).
     *
     * @var list<string>
     */
    public const PROTECTED_COLUMNS = ['balance', 'holder_type', 'holder_id', 'name', 'currency'];

    /**
     * Only harmless data is mass assignable. Holder, name and currency are set
     * by the HasWallet API; balance only changes through the ledger.
     *
     * @var list<string>
     */
    protected $fillable = ['meta'];

    /**
     * Mirrors the column defaults so a freshly created model reports them
     * without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'balance' => 0,
        'min_balance' => 0,
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'min_balance' => 'integer',
            'meta' => 'array',
            'status' => WalletStatus::class,
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? (string) config('wallet.tables.wallets', 'wallets');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holder(): MorphTo
    {
        return $this->morphTo('holder', 'holder_type', 'holder_id');
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Models::transaction(), 'wallet_id');
    }

    /**
     * Add money to the wallet and record the ledger entry.
     *
     * Everything after $type is best passed by name. $type may be a
     * WalletOperation, which then supplies the reference, description and
     * meta itself.
     *
     * @param  int  $amount  positive, in minor units
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidAmount when the amount is not positive
     * @throws InvalidTransaction when the type or details are invalid
     * @throws BalanceOverflow when the balance would overflow
     */
    public function deposit(
        int $amount,
        string|BackedEnum|WalletOperation|null $type = null,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive($amount);
        }

        $details = Details::from($type, CoreType::Deposit, $reference, $description, $meta, $performedBy);

        return (new LedgerWriter)->record($this, $amount, $details);
    }

    /**
     * Take money out of the wallet and record the ledger entry. The minimum
     * balance is checked against the database row, not this instance.
     *
     * Everything after $type is best passed by name. $type may be a
     * WalletOperation, which then supplies the reference, description and
     * meta itself.
     *
     * @param  int  $amount  positive, in minor units
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidAmount when the amount is not positive
     * @throws InvalidTransaction when the type or details are invalid
     * @throws InsufficientFunds when the balance would drop below the minimum balance
     */
    public function withdraw(
        int $amount,
        string|BackedEnum|WalletOperation|null $type = null,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive($amount);
        }

        $details = Details::from($type, CoreType::Withdraw, $reference, $description, $meta, $performedBy);

        return (new LedgerWriter)->record($this, -$amount, $details);
    }

    /**
     * How much could be withdrawn right now, according to this instance.
     *
     * Advisory only: the values may be stale and another process may spend
     * the money first. withdraw() checks the database row itself, so
     * handle InsufficientFunds rather than relying on this.
     */
    public function spendable(): int
    {
        return max(0, $this->balance - $this->min_balance);
    }

    /**
     * Whether spendable() covers the amount. Advisory, like spendable().
     */
    public function canWithdraw(int $amount): bool
    {
        return $amount > 0 && $amount <= $this->spendable();
    }

    /**
     * The supported way to change the lowest balance the wallet may reach.
     */
    public function setMinBalance(int $minBalance): static
    {
        $this->forceFill(['min_balance' => $minBalance])->save();

        return $this;
    }

    /**
     * Columns that can never be changed through Eloquent once the row exists.
     *
     * @return list<string>
     */
    public function protectedColumns(): array
    {
        return [...self::PROTECTED_COLUMNS, $this->getKeyName()];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return WalletBuilder<*>
     */
    public function newEloquentBuilder($query): WalletBuilder
    {
        return new WalletBuilder($query);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $this->assertInsertable($this->getAttributes());

        return parent::performInsert($query);
    }

    /**
     * WalletBuilder refuses insertGetId() so nobody can bulk-insert wallets
     * (e.g. with a non-zero balance) through Eloquent. The model's own save
     * path therefore inserts through the base query builder instead. The
     * attributes are checked again here, after the "creating" event, so a
     * listener cannot slip a balance in after performInsert()'s check.
     *
     * @param  Builder<static>  $query
     * @param  array<string, mixed>  $attributes
     */
    protected function insertAndSetId(Builder $query, $attributes): void
    {
        $this->assertInsertable($attributes);

        $id = $query->toBase()->insertGetId($attributes, $keyName = $this->getKeyName());

        $this->setAttribute($keyName, $id);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        foreach ($this->protectedColumns() as $column) {
            if ($this->isDirty($column)) {
                throw ImmutableWallet::protectedAttribute($this, $column);
            }
        }

        // An "updating" listener could still dirty a protected column after
        // this check; WalletBuilder::update() refuses that write.
        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): never
    {
        throw ImmutableWallet::cannotDelete($this);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertInsertable(array $attributes): void
    {
        if (array_key_exists('balance', $attributes) && ! in_array($attributes['balance'], [0, '0'], true)) {
            throw ImmutableWallet::mustStartEmpty();
        }

        if (isset($attributes['name']) && is_string($attributes['name'])) {
            WalletAttributes::assertName($attributes['name']);
        }

        if (isset($attributes['currency']) && is_string($attributes['currency'])) {
            WalletAttributes::assertCurrency($attributes['currency']);
        }
    }
}
