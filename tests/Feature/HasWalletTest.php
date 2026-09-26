<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Exceptions\WalletAlreadyExists;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

it('creates the default wallet on first use with the holder alias and defaults', function (): void {
    $user = User::create(['name' => 'Ada']);

    $wallet = $user->wallet();

    expect($wallet)->toBeInstanceOf(Wallet::class)
        ->and($wallet->exists)->toBeTrue()
        ->and($wallet->holder_type)->toBe('user')
        ->and($wallet->holder_id)->toBe($user->getKey())
        ->and($wallet->name)->toBe('default')
        ->and($wallet->currency)->toBe('EGP')
        ->and($wallet->min_balance)->toBe(0)
        ->and($wallet->balance)->toBe(0)
        ->and($wallet->status)->toBe(WalletStatus::Active);

    $row = $this->walletRow($wallet->id);

    expect($row['holder_type'])->toBe('user')
        ->and((int) $row['holder_id'])->toBe($user->getKey())
        ->and($row['name'])->toBe('default')
        ->and($row['currency'])->toBe('EGP')
        ->and((int) $row['balance'])->toBe(0)
        ->and((int) $row['min_balance'])->toBe(0)
        ->and($row['status'])->toBe('active');
});

it('returns the same wallet on repeated calls', function (): void {
    $user = User::create(['name' => 'Ada']);

    $first = $user->wallet();
    $second = $user->wallet();
    $fresh = User::find($user->getKey())?->wallet();

    expect($second->id)->toBe($first->id)
        ->and($fresh?->id)->toBe($first->id)
        ->and(DB::table($this->walletsTable())->count())->toBe(1);
});

it('keeps named wallets separate', function (): void {
    $user = User::create(['name' => 'Ada']);

    $default = $user->wallet();
    $savings = $user->wallet('savings');

    expect($savings->id)->not->toBe($default->id)
        ->and($savings->name)->toBe('savings')
        ->and($user->wallet('savings')->id)->toBe($savings->id)
        ->and($user->wallets()->count())->toBe(2);
});

it('keeps wallets of different holder types with the same id separate', function (): void {
    $user = User::create(['name' => 'Ada']);
    $merchant = Merchant::create(['name' => 'Shop']);

    expect($user->getKey())->toBe($merchant->getKey());

    $userWallet = $user->wallet();
    $merchantWallet = $merchant->wallet();

    expect($userWallet->id)->not->toBe($merchantWallet->id)
        ->and($merchantWallet->holder_type)->toBe('merchant')
        ->and($user->wallets()->pluck('id')->all())->toBe([$userWallet->id])
        ->and($merchant->wallets()->pluck('id')->all())->toBe([$merchantWallet->id]);
});

it('uses per-holder defaults for currency and minimum balance', function (): void {
    $merchantWallet = Merchant::create(['name' => 'Shop'])->wallet();
    $adminWallet = Admin::create(['name' => 'Root'])->wallet();

    expect($merchantWallet->min_balance)->toBe(-100_00)
        ->and($merchantWallet->currency)->toBe('EGP')
        ->and($adminWallet->currency)->toBe('USD')
        ->and($adminWallet->min_balance)->toBe(0)
        ->and((int) $this->walletRow($merchantWallet->id)['min_balance'])->toBe(-100_00)
        ->and($this->walletRow($adminWallet->id)['currency'])->toBe('USD');
});

it('resolves the holder relation back to the holder model', function (): void {
    $merchant = Merchant::create(['name' => 'Shop']);
    $walletId = $merchant->wallet()->id;

    $holder = Wallet::findOrFail($walletId)->holder;

    expect($holder)->toBeInstanceOf(Merchant::class)
        ->and($holder?->getKey())->toBe($merchant->getKey());
});

it('creates the wallet inside an outer transaction', function (): void {
    $user = User::create(['name' => 'Ada']);

    $wallet = DB::transaction(fn (): Wallet => $user->wallet());

    expect($wallet->exists)->toBeTrue()
        ->and(DB::table($this->walletsTable())->where('id', $wallet->id)->exists())->toBeTrue();
});

it('works with strict models', function (): void {
    $user = User::create(['name' => 'Ada']);

    $this->withStrictModels(function () use ($user): void {
        $wallet = $user->wallet();
        $again = $user->wallet();
        $named = $user->createWallet('savings', 'USD', -5, ['label' => 'Rainy day']);

        expect($again->id)->toBe($wallet->id)
            ->and($named->meta)->toBe(['label' => 'Rainy day'])
            ->and($user->balance())->toBe(0);
    });
});

it('returns the existing row when the wallet already exists but was not seen yet', function (): void {
    // Simulates losing the creation race: another process inserted the
    // wallet directly (no model involved) before this holder asked for it.
    $user = User::create(['name' => 'Ada']);

    $winnerId = (int) DB::table($this->walletsTable())->insertGetId([
        'holder_type' => 'user',
        'holder_id' => $user->getKey(),
        'name' => 'default',
        'currency' => 'EGP',
        'balance' => 0,
        'min_balance' => 0,
        'status' => 'active',
    ]);

    expect($user->wallet()->id)->toBe($winnerId)
        ->and(DB::table($this->walletsTable())->count())->toBe(1);
});

it('recovers from a unique violation when the lookup missed a concurrent insert', function (): void {
    // The lookup runs first and finds nothing; the competing row is inserted
    // right after it (from the query listener) so our own insert then hits
    // the unique index and wallet() must return the winner instead.
    $user = User::create(['name' => 'Ada']);
    $winnerId = null;
    $table = $this->walletsTable();

    DB::listen(function ($query) use (&$winnerId, $table, $user): void {
        if ($winnerId === null && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, $table)) {
            $winnerId = (int) DB::table($table)->insertGetId([
                'holder_type' => 'user',
                'holder_id' => $user->getKey(),
                'name' => 'default',
                'currency' => 'EGP',
                'balance' => 0,
                'min_balance' => 0,
                'status' => 'active',
            ]);
        }
    });

    $wallet = DB::transaction(fn (): Wallet => $user->wallet());

    expect($winnerId)->not->toBeNull()
        ->and($wallet->id)->toBe($winnerId)
        ->and(DB::table($table)->count())->toBe(1);
});

it('creates a wallet explicitly with the given attributes', function (): void {
    $user = User::create(['name' => 'Ada']);

    $wallet = $user->createWallet('savings', 'USD', -50_00, ['label' => 'Rainy day']);

    expect($wallet->name)->toBe('savings')
        ->and($wallet->currency)->toBe('USD')
        ->and($wallet->min_balance)->toBe(-50_00)
        ->and($wallet->meta)->toBe(['label' => 'Rainy day'])
        ->and($wallet->balance)->toBe(0)
        ->and($user->wallet('savings')->id)->toBe($wallet->id);
});

it('uses the holder defaults for createWallet when currency and minimum are omitted', function (): void {
    $wallet = Merchant::create(['name' => 'Shop'])->createWallet('payouts');

    expect($wallet->currency)->toBe('EGP')
        ->and($wallet->min_balance)->toBe(-100_00);
});

it('refuses to create a wallet that already exists', function (): void {
    $user = User::create(['name' => 'Ada']);
    $existing = $user->wallet('savings');

    expect(fn () => $user->createWallet('savings'))->toThrow(WalletAlreadyExists::class);

    expect(DB::table($this->walletsTable())->count())->toBe(1)
        ->and($user->wallet('savings')->id)->toBe($existing->id);
});

it('keeps the outer transaction usable after createWallet hits a duplicate', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->wallet('savings');

    DB::transaction(function () use ($user): void {
        expect(fn () => $user->createWallet('savings'))->toThrow(WalletAlreadyExists::class);

        // On PostgreSQL this only works because the insert ran in a savepoint.
        $user->createWallet('checking');
    });

    expect($user->wallets()->pluck('name')->sort()->values()->all())->toBe(['checking', 'savings']);
});

it('validates wallet names and currencies', function (string $name, ?string $currency): void {
    $user = User::create(['name' => 'Ada']);

    expect(fn () => $user->createWallet($name, $currency))->toThrow(InvalidWalletAttribute::class);

    expect(DB::table($this->walletsTable())->count())->toBe(0);
})->with([
    'empty name' => ['', null],
    'blank name' => ['   ', null],
    '65-char name' => [str_repeat('a', 65), null],
    'lowercase currency' => ['main', 'egp'],
    'four-letter currency' => ['main', 'EGPP'],
    'two-letter currency' => ['main', 'EG'],
    'digits in currency' => ['main', 'E1P'],
]);

it('accepts a 64-character wallet name', function (): void {
    $name = str_repeat('a', 64);

    expect(User::create(['name' => 'Ada'])->createWallet($name)->name)->toBe($name);
});

it('rejects an invalid name passed to wallet()', function (): void {
    $user = User::create(['name' => 'Ada']);

    expect(fn () => $user->wallet(''))->toThrow(InvalidWalletAttribute::class);

    expect(DB::table($this->walletsTable())->count())->toBe(0);
});

it('refuses to create wallets for an unsaved holder', function (): void {
    expect(fn () => (new User(['name' => 'Ghost']))->wallet())
        ->toThrow(InvalidWalletAttribute::class);

    expect(DB::table($this->walletsTable())->count())->toBe(0);
});

it('reports whether a wallet exists without creating it', function (): void {
    $user = User::create(['name' => 'Ada']);

    expect($user->hasWallet())->toBeFalse()
        ->and($user->hasWallet('savings'))->toBeFalse()
        ->and(DB::table($this->walletsTable())->count())->toBe(0);

    $user->wallet('savings');

    expect($user->hasWallet('savings'))->toBeTrue()
        ->and($user->hasWallet())->toBeFalse();
});

it('returns zero balance for a missing wallet without creating it', function (): void {
    $user = User::create(['name' => 'Ada']);

    expect($user->balance())->toBe(0)
        ->and($user->balance('savings'))->toBe(0)
        ->and(DB::table($this->walletsTable())->count())->toBe(0);
});

it('reads the balance of an existing wallet', function (): void {
    $user = User::create(['name' => 'Ada']);

    DB::table($this->walletsTable())->insert([
        'holder_type' => 'user',
        'holder_id' => $user->getKey(),
        'name' => 'default',
        'currency' => 'EGP',
        'balance' => 12_34,
        'min_balance' => 0,
        'status' => 'active',
    ]);

    expect($user->balance())->toBe(12_34);
});
