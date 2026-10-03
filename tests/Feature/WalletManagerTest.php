<?php

declare(strict_types=1);

use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Anabil\Wallet\Exceptions\NoAuthenticatedHolder;
use Anabil\Wallet\Exceptions\UnknownGuard;
use Anabil\Wallet\Facades\Wallets;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Guest;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\WalletManager;
use Illuminate\Support\Facades\Auth;

it('finds nobody when no configured guard is authenticated', function (): void {
    expect(Wallets::holder())->toBeNull()
        ->and(Wallets::guard())->toBeNull()
        ->and(Wallets::check())->toBeFalse()
        ->and(Wallets::wallet())->toBeNull();
});

it('throws, listing the guards checked, when holderOrFail finds nobody', function (): void {
    expect(fn () => Wallets::holderOrFail())
        ->toThrow(NoAuthenticatedHolder::class, 'web, merchant, admin');

    expect(fn () => Wallets::walletOrFail())
        ->toThrow(NoAuthenticatedHolder::class, 'web, merchant, admin');
});

it('resolves the authenticated user through the web guard', function (): void {
    $user = User::create(['name' => 'Ada']);

    $this->actingAs($user, 'web');

    expect(Wallets::holder()?->is($user))->toBeTrue()
        ->and(Wallets::guard())->toBe('web')
        ->and(Wallets::check())->toBeTrue();
});

it('resolves the authenticated merchant through the merchant guard', function (): void {
    $merchant = Merchant::create(['name' => 'Shop']);

    $this->actingAs($merchant, 'merchant');

    expect(Wallets::holder()?->is($merchant))->toBeTrue()
        ->and(Wallets::guard())->toBe('merchant');
});

it('resolves the authenticated admin through the admin guard, with a USD wallet', function (): void {
    $admin = Admin::create(['name' => 'Root']);

    $this->actingAs($admin, 'admin');

    expect(Wallets::holder()?->is($admin))->toBeTrue()
        ->and(Wallets::guard())->toBe('admin')
        ->and(Wallets::wallet()?->currency)->toBe('USD');
});

it('prefers the first configured guard when two are authenticated', function (): void {
    $user = User::create(['name' => 'Ada']);
    $merchant = Merchant::create(['name' => 'Shop']);

    $this->actingAs($user, 'web');
    $this->actingAs($merchant, 'merchant');

    // Default order from ConfiguresWalletEnvironment is [web, merchant, admin].
    expect(Wallets::guard())->toBe('web')
        ->and(Wallets::holder()?->is($user))->toBeTrue();

    config(['wallet.guards' => ['merchant', 'web', 'admin']]);

    expect(Wallets::guard())->toBe('merchant')
        ->and(Wallets::holder()?->is($merchant))->toBeTrue();
});

it('skips a configured guard that is not defined in auth.guards', function (): void {
    $user = User::create(['name' => 'Ada']);

    config(['wallet.guards' => ['does_not_exist', 'web']]);

    $this->actingAs($user, 'web');

    expect(Wallets::guard())->toBe('web')
        ->and(Wallets::holder()?->is($user))->toBeTrue();
});

it('skips an authenticated user that does not use HasWallet', function (): void {
    $guest = Guest::create(['name' => 'Ghost']);
    $user = User::create(['name' => 'Ada']);

    config(['wallet.guards' => ['guest', 'web']]);

    $this->actingAs($guest, 'guest');
    $this->actingAs($user, 'web');

    expect(Wallets::guard())->toBe('web')
        ->and(Wallets::holder()?->is($user))->toBeTrue();
});

it('resolves a named wallet', function (): void {
    $user = User::create(['name' => 'Ada']);

    $this->actingAs($user, 'web');

    $points = Wallets::wallet('points');

    expect($points)->toBeInstanceOf(Wallet::class)
        ->and($points->name)->toBe('points');
});

it('creates the wallet on first use and returns the same one afterwards', function (): void {
    $user = User::create(['name' => 'Ada']);

    $this->actingAs($user, 'web');

    $first = Wallets::walletOrFail();
    $second = Wallets::walletOrFail();

    expect($second->id)->toBe($first->id)
        ->and(Wallet::query()->count())->toBe(1);
});

it('resolves the holder on a specific guard regardless of wallet.guards', function (): void {
    $merchant = Merchant::create(['name' => 'Shop']);

    config(['wallet.guards' => ['web']]);

    $this->actingAs($merchant, 'merchant');

    expect(Wallets::forGuard('merchant')?->is($merchant))->toBeTrue()
        // Not in wallet.guards, so holder() would not find it.
        ->and(Wallets::holder())->toBeNull();
});

it('throws for a guard that is not defined in auth.guards', function (): void {
    expect(fn () => Wallets::forGuard('nope'))
        ->toThrow(UnknownGuard::class, 'nope');
});

it('rejects a wallet.guards config that is not a list of non-empty strings', function (mixed $invalid): void {
    config(['wallet.guards' => $invalid]);

    expect(fn () => Wallets::check())->toThrow(InvalidConfiguration::class, 'wallet.guards');
})->with([
    'a plain string' => ['web'],
    'a list with an empty string' => [['']],
    'a list with a non-string' => [[1]],
]);

it('resolves the same singleton through the facade', function (): void {
    expect(Wallets::getFacadeRoot())->toBe($this->app->make(WalletManager::class));
});

it('reflects a logout between calls without caching the holder', function (): void {
    $user = User::create(['name' => 'Ada']);

    $this->actingAs($user, 'web');

    expect(Wallets::holder()?->is($user))->toBeTrue();

    Auth::guard('web')->logout();

    expect(Wallets::holder())->toBeNull();
});
