<?php

declare(strict_types=1);

namespace Anabil\Wallet\Concerns;

use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Exceptions\WalletAlreadyExists;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\WalletAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lets any Eloquent model hold wallets. Override walletCurrency() and
 * walletMinBalance() on the holder model to change the defaults used when a
 * wallet is created implicitly by wallet().
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
     * Explicitly create a wallet.
     *
     * @param  array<array-key, mixed>  $meta
     *
     * @throws WalletAlreadyExists when the holder already has a wallet with this name
     */
    public function createWallet(string $name, ?string $currency = null, ?int $minBalance = null, array $meta = []): Wallet
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
     * The named wallet's balance in minor units, or 0 when it does not exist.
     * Never creates a wallet.
     */
    public function balance(?string $name = null): int
    {
        $wallet = $this->findWallet($this->resolveWalletName($name));

        return $wallet === null ? 0 : $wallet->balance;
    }

    /**
     * Currency for wallets created without an explicit currency.
     */
    public function walletCurrency(): string
    {
        return (string) config('wallet.default_currency', 'EGP');
    }

    /**
     * Minimum balance for wallets created without an explicit one.
     */
    public function walletMinBalance(): int
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
     * @param  array<array-key, mixed>  $meta
     */
    private function insertWallet(string $name, string $currency, int $minBalance, array $meta): Wallet
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
            'min_balance' => $minBalance,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $wallet->newModelQuery()->withSavepointIfNeeded(fn () => $wallet->save());

        $wallet->setRelation('holder', $this);

        return $wallet;
    }
}
