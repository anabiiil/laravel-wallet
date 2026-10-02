<?php

declare(strict_types=1);

use Anabil\Wallet\Database\WalletBuilder;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Relations\Relation;

it('reads its table name from config', function (): void {
    config(['wallet.tables.wallets' => 'custom_wallets']);

    expect((new Wallet)->getTable())->toBe('custom_wallets');
});

it('uses the wallet builder', function (): void {
    expect(Wallet::query())->toBeInstanceOf(WalletBuilder::class);
});

it('only allows meta to be mass assigned', function (): void {
    expect((new Wallet)->getFillable())->toBe(['meta']);
});

it('casts its attributes', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $wallet->update(['meta' => ['a' => 1]]);

    $fresh = Wallet::findOrFail($wallet->id);

    expect($fresh->balance)->toBe('0.00')
        ->and($fresh->min_balance)->toBe('0.00')
        ->and($fresh->scale)->toBe(2)
        ->and($fresh->balanceInMinor())->toBe(0)
        ->and($fresh->minBalanceInMinor())->toBe(0)
        ->and($fresh->status)->toBe(WalletStatus::Active)
        ->and($fresh->meta)->toBe(['a' => 1]);
});

it('updates meta through update()', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();

    expect($wallet->update(['meta' => ['label' => 'Main']]))->toBeTrue();

    expect(json_decode((string) $this->walletRow($wallet->id)['meta'], true))->toBe(['label' => 'Main']);
});

it('changes the minimum balance through setMinBalance()', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();

    expect($wallet->setMinBalance(-25))->toBe($wallet)
        ->and($wallet->min_balance)->toBe('-25.00')
        ->and($wallet->isDirty())->toBeFalse()
        ->and((int) $this->walletRow($wallet->id)['min_balance'])->toBe(-25_00);
});

it('lets the status be changed', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();

    $wallet->status = WalletStatus::Frozen;
    $wallet->save();

    expect($this->walletRow($wallet->id)['status'])->toBe('frozen');
});

it('registers the configured holders in the morph map', function (): void {
    expect(Relation::getMorphedModel('user'))->toBe(User::class)
        ->and(Relation::getMorphedModel('merchant'))->toBe(Merchant::class)
        ->and(Relation::getMorphedModel('admin'))->toBe(Admin::class);
});

it('resolves the configured model classes', function (): void {
    expect(Models::wallet())->toBe(Wallet::class)
        ->and(Models::transaction())->toBe(WalletTransaction::class);
});

it('rejects configured models that do not extend the package models', function (string $key, mixed $class): void {
    config(["wallet.models.{$key}" => $class]);

    expect(fn () => $key === 'wallet' ? Models::wallet() : Models::transaction())
        ->toThrow(WalletException::class);
})->with([
    'wallet as unrelated model' => ['wallet', User::class],
    'wallet as missing class' => ['wallet', 'App\\Models\\Nope'],
    'transaction as wallet' => ['transaction', Wallet::class],
    'transaction as non-string' => ['transaction', 42],
]);

it('lists the wallet transactions', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $id = $this->insertTransactionRow($wallet->id);

    expect($wallet->transactions()->pluck('id')->all())->toBe([$id]);
});
