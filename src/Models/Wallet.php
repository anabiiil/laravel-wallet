<?php

declare(strict_types=1);

namespace Anabil\Wallet\Models;

use Anabil\Wallet\Contracts\WalletOperation;
use Anabil\Wallet\Database\WalletBuilder;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidStatusTransition;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Exceptions\InvalidTransfer;
use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Exceptions\WalletClosed;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Exceptions\WalletFrozen;
use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\LedgerWriter;
use Anabil\Wallet\Ledger\StatusWriter;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\Scales;
use Anabil\Wallet\Support\WalletAttributes;
use Anabil\Wallet\TransferResult;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * A holder's wallet. Its balance, holder, name, currency, scale and status
 * can never be changed through Eloquent, and it can never be deleted: the checks
 * live in the model's persistence internals (performInsert / performUpdate /
 * performDeleteOnModel) and in WalletBuilder, not in model events, so they
 * also hold for saveQuietly(), createQuietly() and Model::withoutEvents().
 *
 * Amounts are stored as integers in minor units and exposed in major units:
 * balance and min_balance read as decimal strings with exactly scale()
 * decimals ("250.50"), and every method taking an amount accepts an int, a
 * float or a decimal string in major units (250, 100.5, "100.50"), refusing
 * anything it would have to round. The raw integers are available through
 * balanceInMinor() and minBalanceInMinor().
 *
 * The scale (2 or 3 decimal places) is set from the config when the wallet
 * is created and never changes, so the stored integers keep their meaning
 * whatever the config says later.
 *
 * Status: a wallet is born active. freeze() stops money from leaving it
 * (credits still land; a forced reversal may still debit it), unfreeze()
 * makes it active again, and close() ends it for good once its balance is
 * zero: a closed wallet takes no movement at all. The rules are enforced by
 * the database row, inside the same guarded UPDATE that moves the money,
 * never by this instance's (possibly stale) status.
 *
 * @property int $id
 * @property string $holder_type
 * @property int|string $holder_id
 * @property string $name
 * @property string $currency
 * @property int $scale
 * @property-read string $balance
 * @property string $min_balance
 * @property-read WalletStatus $status
 * @property array<array-key, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Wallet extends Model
{
    /**
     * Columns that are fixed once the wallet exists, or (status) only change
     * through freeze(), unfreeze() and close(). The primary key is
     * protected as well (see protectedColumns()).
     *
     * @var list<string>
     */
    public const PROTECTED_COLUMNS = ['balance', 'holder_type', 'holder_id', 'name', 'currency', 'scale', 'status'];

    /**
     * Bookkeeping for undoing status changes when an enclosing transaction
     * rolls back (see recordStatusChange()): the version of the current
     * in-memory status, and the last version handed out.
     */
    private int $statusVersion = 0;

    private int $statusVersions = 0;

    /**
     * Only harmless data is mass assignable. Holder, name, currency and scale
     * are set by the HasWallet API; balance only changes through the ledger.
     *
     * @var list<string>
     */
    protected $fillable = ['meta'];

    /**
     * Mirrors the column defaults (raw, in minor units) so a freshly created
     * model reports them without a refresh.
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
            'scale' => 'integer',
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
        // The entries get this instance as their wallet (chaperone), so they
        // can format their amounts without the eager load
        // WalletTransaction::$with would otherwise run.
        return $this->hasMany(Models::transaction(), 'wallet_id')->without('wallet')->chaperone('wallet');
    }

    /**
     * Add money to the wallet and record the ledger entry.
     *
     * Everything after $type is best passed by name. $type may be a
     * WalletOperation, which then supplies the reference, description and
     * meta itself.
     *
     * With an $idempotencyKey (at most 128 characters, unique per wallet),
     * repeating the same call returns the entry recorded the first time
     * instead of writing again; reusing the key for a different operation
     * throws IdempotencyConflict. A replay does not change this instance:
     * refresh() it to read the current balance.
     *
     * @param  int|float|string  $amount  positive, in major units (e.g. 250, 100.5 or "100.50")
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidAmount when the amount is not positive, malformed, too precise for the wallet's scale or too large
     * @throws InvalidTransaction when the type, details or idempotency key are invalid
     * @throws BalanceOverflow when the balance would overflow
     * @throws WalletClosed when the wallet is closed
     * @throws IdempotencyConflict when the key was already used on this wallet for a different operation
     */
    public function deposit(
        int|float|string $amount,
        string|BackedEnum|WalletOperation|null $type = null,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
        ?string $idempotencyKey = null,
    ): WalletTransaction {
        $minor = $this->positiveMinor($amount);

        $details = Details::from($type, CoreType::Deposit, $reference, $description, $meta, $performedBy);

        return (new LedgerWriter)->record($this, $minor, $details, $idempotencyKey);
    }

    /**
     * Take money out of the wallet and record the ledger entry. The minimum
     * balance is checked against the database row, not this instance.
     *
     * Everything after $type is best passed by name. $type may be a
     * WalletOperation, which then supplies the reference, description and
     * meta itself.
     *
     * With an $idempotencyKey (at most 128 characters, unique per wallet),
     * repeating the same call returns the entry recorded the first time
     * instead of writing again; reusing the key for a different operation
     * throws IdempotencyConflict. A replay does not change this instance:
     * refresh() it to read the current balance.
     *
     * @param  int|float|string  $amount  positive, in major units (e.g. 250, 100.5 or "100.50")
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidAmount when the amount is not positive, malformed, too precise for the wallet's scale or too large
     * @throws InvalidTransaction when the type, details or idempotency key are invalid
     * @throws InsufficientFunds when the balance would drop below the minimum balance
     * @throws WalletFrozen when the wallet is frozen
     * @throws WalletClosed when the wallet is closed
     * @throws IdempotencyConflict when the key was already used on this wallet for a different operation
     */
    public function withdraw(
        int|float|string $amount,
        string|BackedEnum|WalletOperation|null $type = null,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
        ?string $idempotencyKey = null,
    ): WalletTransaction {
        $minor = $this->positiveMinor($amount);

        $details = Details::from($type, CoreType::Withdraw, $reference, $description, $meta, $performedBy);

        return (new LedgerWriter)->record($this, -$minor, $details, $idempotencyKey);
    }

    /**
     * Move money from this wallet to another one, of any holder: a
     * transfer_out entry here and a transfer_in entry on the recipient,
     * written together or not at all. Both wallets must use the same
     * currency (and so the same scale) and database connection. The amount
     * is read with this wallet's scale.
     *
     * Everything after $amount is best passed by name. $description goes on
     * this wallet's entry and $recipientDescription on the recipient's
     * (it defaults to $description); the reference, meta and actor go on
     * both.
     *
     * With an $idempotencyKey (at most 128 characters, unique per sending
     * wallet), repeating the same call returns the transfer recorded the
     * first time (TransferResult::replayed() is true) instead of writing
     * again; reusing the key for a different operation throws
     * IdempotencyConflict. The key is stored on this wallet's entry only.
     * A replay does not change either instance: refresh() them to read the
     * current balances.
     *
     * @param  int|float|string  $amount  positive, in major units (e.g. 250, 100.5 or "100.50")
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidAmount when the amount is not positive, malformed, too precise for the wallet's scale or too large
     * @throws InvalidTransaction when the details or idempotency key are invalid
     * @throws InvalidTransfer when the recipient is this wallet or uses another currency, scale or connection
     * @throws InsufficientFunds when this wallet would drop below its minimum balance
     * @throws BalanceOverflow when the recipient's balance would overflow
     * @throws WalletFrozen when this wallet is frozen (the recipient may be)
     * @throws WalletClosed when either wallet is closed
     * @throws IdempotencyConflict when the key was already used on this wallet for a different operation
     */
    public function transferTo(
        Wallet $recipient,
        int|float|string $amount,
        ?Model $reference = null,
        ?string $description = null,
        ?string $recipientDescription = null,
        array $meta = [],
        ?Model $performedBy = null,
        ?string $idempotencyKey = null,
    ): TransferResult {
        $minor = $this->positiveMinor($amount);

        $out = Details::internal(CoreType::TransferOut, $reference, $description, $meta, $performedBy);
        $in = Details::internal(CoreType::TransferIn, $reference, $recipientDescription ?? $description, $meta, $performedBy);

        return (new LedgerWriter)->transfer($this, $recipient, $minor, $out, $in, $idempotencyKey);
    }

    /**
     * Stop money from leaving the wallet: withdrawals, outgoing transfers
     * and reversals that would debit it are refused with WalletFrozen
     * (a reversal with force: true still goes through, e.g. a chargeback).
     * Credits still land. A no-op, without an event, when the wallet is
     * already frozen.
     *
     * The change is a single guarded UPDATE of the row, so it takes effect
     * for every request at once: once it commits, no debit passes.
     * WalletStatusChanged is dispatched after the commit.
     *
     * @param  string|null  $reason  at most 255 characters, carried by the event
     *
     * @throws InvalidStatusTransition when the wallet is closed, or the reason / actor are invalid
     */
    public function freeze(?string $reason = null, ?Model $performedBy = null): static
    {
        (new StatusWriter)->transition($this, WalletStatus::Frozen, $reason, $performedBy);

        return $this;
    }

    /**
     * Make a frozen wallet active again. A no-op, without an event, when it
     * is already active. WalletStatusChanged is dispatched after the
     * commit.
     *
     * @param  string|null  $reason  at most 255 characters, carried by the event
     *
     * @throws InvalidStatusTransition when the wallet is closed, or the reason / actor are invalid
     */
    public function unfreeze(?string $reason = null, ?Model $performedBy = null): static
    {
        (new StatusWriter)->transition($this, WalletStatus::Active, $reason, $performedBy);

        return $this;
    }

    /**
     * Close the wallet for good: no money moves on it again, in either
     * direction, and it cannot be reopened. Only an empty wallet (a balance
     * of exactly zero, checked against the database row by the UPDATE
     * itself) can be closed, whether it is active or frozen. A no-op,
     * without an event, when it is already closed. WalletStatusChanged is
     * dispatched after the commit.
     *
     * @param  string|null  $reason  at most 255 characters, carried by the event
     *
     * @throws InvalidStatusTransition when the balance is not zero, or the reason / actor are invalid
     */
    public function close(?string $reason = null, ?Model $performedBy = null): static
    {
        (new StatusWriter)->transition($this, WalletStatus::Closed, $reason, $performedBy);

        return $this;
    }

    /**
     * Whether this instance says the wallet is active. Advisory only, like
     * spendable(): the status may have changed since the instance was read,
     * and the ledger checks the database row itself.
     */
    public function isActive(): bool
    {
        return $this->currentStatus() === WalletStatus::Active;
    }

    /**
     * Whether this instance says the wallet is frozen. Advisory, like
     * isActive().
     */
    public function isFrozen(): bool
    {
        return $this->currentStatus() === WalletStatus::Frozen;
    }

    /**
     * Whether this instance says the wallet is closed. Advisory, like
     * isActive().
     */
    public function isClosed(): bool
    {
        return $this->currentStatus() === WalletStatus::Closed;
    }

    /**
     * Puts the stored status on the instance, clean.
     *
     * @internal used by StatusWriter
     */
    public function syncStatus(WalletStatus $status): void
    {
        $this->attributes['status'] = $status->value;
        $this->syncOriginalAttributes('status');
    }

    /**
     * Puts a committed status change (and its updated_at) on the instance,
     * clean, and returns the closure that undoes it if the enclosing
     * transaction rolls back.
     *
     * Every change gets a new version. An undo only applies while the
     * instance is at or past its version, and steps it back to the version
     * before it; so whatever order Laravel runs the rollback callbacks of
     * several changes in, the instance ends up with the status it had
     * before the earliest change rolled back.
     *
     * @return Closure(): void
     *
     * @internal used by StatusWriter
     */
    public function recordStatusChange(WalletStatus $from, WalletStatus $to, ?string $timestampColumn, DateTimeInterface $now): Closure
    {
        $previousVersion = $this->statusVersion;
        $version = $this->statusVersion = ++$this->statusVersions;
        $previousTimestamp = $timestampColumn === null ? null : ($this->attributes[$timestampColumn] ?? null);

        $this->syncStatus($to);

        if ($timestampColumn !== null) {
            $this->setAttribute($timestampColumn, $this->fromDateTime($now));
            $this->syncOriginalAttributes($timestampColumn);
        }

        return function () use ($from, $version, $previousVersion, $timestampColumn, $previousTimestamp, $now): void {
            if ($this->statusVersion < $version) {
                return;
            }

            $this->statusVersion = $previousVersion;
            $this->syncStatus($from);

            if ($timestampColumn !== null) {
                $current = $this->getAttribute($timestampColumn);

                if (! $current instanceof DateTimeInterface || $current >= $now) {
                    $this->setAttribute($timestampColumn, $previousTimestamp);
                    $this->syncOriginalAttributes($timestampColumn);
                }
            }
        };
    }

    /**
     * How much could be withdrawn right now, according to this instance, as
     * a decimal string in major units ("0.00" when nothing can).
     *
     * Advisory only: the values may be stale and another process may spend
     * the money first. withdraw() checks the database row itself, so
     * handle InsufficientFunds rather than relying on this.
     */
    public function spendable(): string
    {
        $balance = $this->balanceInMinor();
        $minBalance = $this->minBalanceInMinor();

        return $balance > $minBalance
            ? Amount::difference($balance, $minBalance, $this->scale())
            : $this->toDecimal(0);
    }

    /**
     * Whether spendable() covers the amount, given in major units. Advisory,
     * like spendable().
     *
     * @throws InvalidAmount when the amount is malformed, too precise for the wallet's scale or too large
     */
    public function canWithdraw(int|float|string $amount): bool
    {
        $minor = Amount::toMinor($amount, $this->scale());
        $balance = $this->balanceInMinor();
        $minBalance = $this->minBalanceInMinor();

        if ($minor <= 0 || $balance < $minBalance) {
            return false;
        }

        // A difference too large for an int covers any int amount.
        if ($minBalance < 0 && $balance > PHP_INT_MAX + $minBalance) {
            return true;
        }

        return $minor <= $balance - $minBalance;
    }

    /**
     * The supported way to change the lowest balance the wallet may reach,
     * in major units. It may be negative (a credit line).
     *
     * @throws InvalidAmount when the amount is malformed, too precise for the wallet's scale or too large
     */
    public function setMinBalance(int|float|string $minBalance): static
    {
        $this->forceFill(['min_balance' => $minBalance])->save();

        return $this;
    }

    /**
     * The number of decimal places of this wallet's amounts (2 or 3), fixed
     * when it was created.
     *
     * A wallet that is not saved yet and has no scale uses the one the
     * config gives its currency, as it will get when it is saved.
     *
     * @throws InvalidWalletAttribute when the wallet was loaded without its scale column
     */
    public function scale(): int
    {
        $scale = $this->attributes['scale'] ?? null;

        if (is_int($scale) || (is_string($scale) && ctype_digit($scale))) {
            return (int) $scale;
        }

        if ($this->exists) {
            throw InvalidWalletAttribute::scaleNotLoaded($this->getKey());
        }

        $currency = $this->attributes['currency'] ?? null;

        return Scales::forCurrency(is_string($currency) ? $currency : (string) Config::get('wallet.default_currency', 'EGP'));
    }

    /**
     * The balance with its currency, e.g. "250.50 EGP".
     */
    public function formatted(): string
    {
        return $this->balance.' '.$this->currency;
    }

    /**
     * The balance as stored: an integer in minor units.
     */
    public function balanceInMinor(): int
    {
        return self::rawMinor($this->attributes['balance'] ?? 0);
    }

    /**
     * The minimum balance as stored: an integer in minor units.
     */
    public function minBalanceInMinor(): int
    {
        return self::rawMinor($this->attributes['min_balance'] ?? 0);
    }

    /**
     * An amount in minor units as a decimal string with this wallet's
     * scale: toDecimal(25050) is "250.50" on a 2-decimal wallet.
     */
    public function toDecimal(int $minor): string
    {
        return Amount::format($minor, $this->scale());
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
        // The scale is fixed now, from the config for the wallet's currency,
        // unless it was given explicitly.
        if (($this->attributes['scale'] ?? null) === null) {
            $this->attributes['scale'] = $this->scale();
        }

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
     * Mirrors Model::performUpdate(), but writes through the base query
     * builder: WalletBuilder refuses query-level writes to min_balance
     * (where a raw number would be minor units), while the model writes the
     * value its setter already converted from major units. The protected
     * columns are checked before the "updating" event and again right
     * before the write, so a listener cannot slip a change in.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        $this->assertProtectedColumnsClean();

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $this->assertProtectedColumnsClean();

            // updateTimestamps() already put updated_at in $dirty, which is
            // all Builder::update() would have added.
            $this->setKeysForSaveQuery($query)->toBase()->update($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * @throws ImmutableWallet
     */
    private function assertProtectedColumnsClean(): void
    {
        foreach ($this->protectedColumns() as $column) {
            if ($this->isDirty($column)) {
                throw ImmutableWallet::protectedAttribute($this, $column);
            }
        }
    }

    protected function performDeleteOnModel(): never
    {
        throw ImmutableWallet::cannotDelete($this);
    }

    /**
     * Model-level increment() / decrement() would add a raw number to the
     * decimal string the accessor reads while the query adds it in minor
     * units, leaving the instance and the row disagreeing. They are refused
     * on the protected columns and on min_balance; use setMinBalance(), or a
     * query (Wallet::query()->whereKey($id)->increment(...)), which works on
     * the stored minor units.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<array-key, mixed>  $extra
     * @param  string  $method
     * @return int|false
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $columns = [$column, ...array_keys($extra)];

        foreach ($columns as $name) {
            if (in_array($name, $this->protectedColumns(), true)) {
                throw ImmutableWallet::protectedAttribute($this, (string) $name);
            }
        }

        if (in_array('min_balance', $columns, true)) {
            throw new WalletException(sprintf(
                'Cannot %s [min_balance] on wallet [%s] through the model: use setMinBalance() with the new value in major units.',
                $method,
                (string) $this->getKey(),
            ));
        }

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    /**
     * Reads as a decimal string with the wallet's scale. There is no
     * setter: the balance only changes through the ledger, which stores
     * the raw integer.
     *
     * @return Attribute<string, never>
     */
    protected function balance(): Attribute
    {
        return Attribute::get(fn (mixed $value): string => $this->toDecimal(self::rawMinor($value)));
    }

    /**
     * Reads as a decimal string with the wallet's scale and is written in
     * major units, like setMinBalance().
     *
     * @return Attribute<string, mixed>
     */
    protected function minBalance(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => $this->toDecimal(self::rawMinor($value)),
            set: fn (mixed $value): int => Amount::toMinor(
                is_int($value) || is_float($value) || is_string($value) ? $value : throw InvalidAmount::invalidType($value),
                $this->scale(),
            ),
        );
    }

    /**
     * @throws InvalidAmount when the amount is not positive, malformed, too precise or too large
     */
    private function positiveMinor(int|float|string $amount): int
    {
        $minor = Amount::toMinor($amount, $this->scale());

        if ($minor <= 0) {
            throw InvalidAmount::notPositive($this->toDecimal($minor));
        }

        return $minor;
    }

    /**
     * The in-memory status, or null when the stored value is not a valid
     * one (which only a manual edit of the table can produce).
     */
    private function currentStatus(): ?WalletStatus
    {
        $status = $this->attributes['status'] ?? null;

        return is_string($status) ? WalletStatus::tryFrom($status) : null;
    }

    /**
     * A stored integer column as an int, whatever type the driver returned.
     */
    private static function rawMinor(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertInsertable(array $attributes): void
    {
        if (array_key_exists('balance', $attributes) && ! in_array($attributes['balance'], [0, '0'], true)) {
            throw ImmutableWallet::mustStartEmpty();
        }

        if (array_key_exists('status', $attributes)
            && $attributes['status'] !== WalletStatus::Active->value
            && $attributes['status'] !== WalletStatus::Active) {
            throw ImmutableWallet::mustStartActive();
        }

        if (isset($attributes['name']) && is_string($attributes['name'])) {
            WalletAttributes::assertName($attributes['name']);
        }

        if (isset($attributes['currency']) && is_string($attributes['currency'])) {
            WalletAttributes::assertCurrency($attributes['currency']);
        }

        if (array_key_exists('scale', $attributes) && ! (is_int($attributes['scale']) && Scales::isAllowed($attributes['scale']))) {
            throw InvalidWalletAttribute::invalidScale($attributes['scale']);
        }
    }
}
