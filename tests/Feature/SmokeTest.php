<?php

declare(strict_types=1);

use Anabil\Wallet\WalletServiceProvider;
use Illuminate\Support\Facades\DB;

it('loads the wallet service provider', function (): void {
    expect($this->app->getLoadedProviders())
        ->toHaveKey(WalletServiceProvider::class);
});

it('merges the default wallet config', function (): void {
    expect(config('wallet.default_currency'))->toBe('EGP');
});

it('connects to the configured database', function (): void {
    expect(DB::connection()->select('select 1'))->not->toBeEmpty();
});
