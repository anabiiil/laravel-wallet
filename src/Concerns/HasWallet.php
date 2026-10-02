<?php

declare(strict_types=1);

namespace Anabil\Wallet\Concerns;

use Anabil\Wallet\Contracts\WalletOperation;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransfer;
use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Exceptions\WalletAlreadyExists;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\Scales;
use Anabil\Wallet\Support\WalletAttributes;
use Anabil\Wallet\TransferResult;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lets any Eloquent model hold wallets. Override walletCurrency() and
 * walletMinBalance() on the holder model to change the defaults used when a
 * wallet is created implicitly by wallet().
 *
 * Amounts are in major units: passed as an int, a float or a decimal string
 * (250, 100.5, "100.50") and read back as decimal strings ("250.50").
 *
 * @phpstan-require-extends Model
 */
trait HasWallet
{
    /**
     * @return MorphMany<Wallet, $this>
     */
    public function wallets(): MorphMany
    {
        return $this->morphMany(Models::wallet(), 'holder', 'holder_type', 'holder_id');
    }

    /**
     * Get the named wallet (the configured default when no name is given),
     * creating it with the holder's default currency and minimum balance on
     * first use.
     */
    public function wallet(?string $name = null): Wallet
    {
        $name = $this->resolveWalletName($name);

        if ($wallet = $this->findWallet($name)) {
            return $wallet;
        }

        try {
            return $this->insertWallet($name, $this->walletCurrency(), $this->walletMinBalance(), []);
        } catch (UniqueConstraintViolationException $e) {
            // Someone else created it between our lookup and our insert:
            // return the winner's row.
            return $this->findWallet($name) ?? throw $e;
        }
    }

    public function hasWallet(?string $name = null): bool
    {
        return $this->wallets()->where('name', $this->resolveWalletName($name))->exists();
    }

    /**
     * Explicitly create a wallet. Its scale comes from the config for its
     * currency; $minBalance is in major units of that scale and may be
     * negative (a credit line).
     *
     * @param  array<array-key, mixed>  $meta
     *
     * @throws WalletAlreadyExists when the holder already has a wallet with this name
     * @throws InvalidAmount when the minimum balance is malformed, too precise or too large
     */
    public function createWallet(string $name, ?string $currency = null, int|float|string|null $minBalance = null, array $meta = []): Wallet
    {
        WalletAttributes::assertName($name);

        try {
            return $this->insertWallet(
                $name,
                $currency ?? $this->walletCurrency(),
                $minBalance ?? $this->walletMinBalance(),
                $meta,
            );
        } catch (UniqueConstraintViolationException $e) {
            throw WalletAlreadyExists::for($this, $name, $e);
        }
    }

    /**
     * The named wallet's balance as a decimal string ("250.50"), or zero
     * ("0.00") when it does not exist, formatted with the scale the
     * holder's default currency would get. Never creates a wallet.
     */
    public function balance(?string $name = null): string
    {
        $wallet = $this->findWallet($this->resolveWalletName($name));

        return $wallet === null
            ? Amount::format(0, Scales::forCurrency($this->walletCurrency()))
            : $wallet->balance;
    }

    /**
     * Deposit into the default wallet (created on first use). For a named
     * wallet use $holder->wallet('name')->deposit(...).
     *
     * @param  int|float|string  $amount  positive, in major units of the wallet's scale
     * @param  array<array-key, mixed>  $meta
     *
     * @see Wallet::deposit()
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
        return $this->wallet()->deposit($amount, $type, $reference, $description, $meta, $performedBy, $idempotencyKey);
    }

    /**
     * Withdraw from the default wallet (created on first use). For a named
     * wallet use $holder->wallet('name')->withdraw(...).
     *
     * @param  int|float|string  $amount  positive, in major units of the wallet's scale
     * @param  array<array-key, mixed>  $meta
     *
     * @see Wallet::withdraw()
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
        return $this->wallet()->withdraw($amount, $type, $reference, $description, $meta, $performedBy, $idempotencyKey);
    }

    /**
     * Transfer from the default wallet (created on first use) to another
     * wallet, or to the default wallet of another holder (created on first
     * use). For a named wallet use $holder->wallet('name')->transferTo(...).
     *
     * @param  int|float|string  $amount  positive, in major units of the sending wallet's scale
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidTransfer when the recipient model does not hold wallets
     *
     * @see Wallet::transferTo()
     */
    public function transferTo(
        Model|Wallet $recipient,
        int|float|string $amount,
        ?Model $reference = null,
        ?string $description = null,
        ?string $recipientDescription = null,
        array $meta = [],
        ?Model $performedBy = null,
        ?string $idempotencyKey = null,
    ): TransferResult {
        if (! $recipient instanceof Wallet) {
            if (! in_array(HasWallet::class, class_uses_recursive($recipient), true) || ! method_exists($recipient, 'wallet')) {
                throw InvalidTransfer::recipientHoldsNoWallet($recipient);
            }

            $wallet = $recipient->wallet();

            if (! $wallet instanceof Wallet) {
                throw InvalidTransfer::recipientHoldsNoWallet($recipient);
            }

            $recipient = $wallet;
        }

        return $this->wallet()->transferTo(
            $recipient,
            $amount,
            $reference,
            $description,
            $recipientDescription,
            $meta,
            $performedBy,
            $idempotencyKey,
        );
    }

    /**
     * Currency for wallets created without an explicit currency.
     */
    public function walletCurrency(): string
    {
        return (string) config('wallet.default_currency', 'EGP');
    }

    /**
     * Minimum balance for wallets created without an explicit one, in major
     * units (e.g. -100 for a credit line of 100.00).
     */
    public function walletMinBalance(): int|float|string
    {
        return 0;
    }

    private function resolveWalletName(?string $name): string
    {
        $name ??= (string) config('wallet.default_wallet', 'default');

        WalletAttributes::assertName($name);

        return $name;
    }

    private function findWallet(string $name): ?Wallet
    {
        $wallet = $this->wallets()->where('name', $name)->first();

        $wallet?->setRelation('holder', $this);

        return $wallet;
    }

    /**
     * Inserts the wallet by saving a model instance (fillable is only meta, so
     * no mass assignment is involved). The insert runs inside a savepoint when
     * a transaction is already open, so a unique violation from a concurrent
     * insert only rolls back the savepoint: on PostgreSQL the outer
     * transaction would otherwise be aborted and unusable.
     *
     * The scale is fixed here, from the config for the currency, and set
     * before min_balance so the latter is read with it.
     *
     * @param  array<array-key, mixed>  $meta
     */
    private function insertWallet(string $name, string $currency, int|float|string $minBalance, array $meta): Wallet
    {
        WalletAttributes::assertName($name);
        WalletAttributes::assertCurrency($currency);

        if ($this->getKey() === null) {
            throw InvalidWalletAttribute::holderNotPersisted(static::class);
        }

        $class = Models::wallet();
        $wallet = new $class;

        $wallet->forceFill([
            'holder_type' => $this->getMorphClass(),
            'holder_id' => $this->getKey(),
            'name' => $name,
            'currency' => $currency,
            'scale' => Scales::forCurrency($currency),
            'min_balance' => $minBalance,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $wallet->newModelQuery()->withSavepointIfNeeded(fn () => $wallet->save());

        $wallet->setRelation('holder', $this);

        return $wallet;
    }
}
