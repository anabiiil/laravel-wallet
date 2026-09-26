<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
 * Every v1 bypass must now fail loudly (or be ignored safely), and the
 * database must be left exactly as it was.
 */

beforeEach(function (): void {
    $this->holder = User::create(['name' => 'Ada']);
    $this->other = Merchant::create(['name' => 'Mallory']);
    $this->wallet = $this->holder->wallet();
    $this->walletCount = DB::table($this->walletsTable())->count();
    $this->walletId = $this->wallet->id;
    $this->snapshot = $this->walletRow($this->walletId);
});

it('ignores non-fillable attributes passed to update()', function (): void {
    $this->wallet->update([
        'holder_id' => $this->other->getKey(),
        'holder_type' => 'merchant',
        'balance' => 999,
        'currency' => 'USD',
        'name' => 'stolen',
    ]);

    expect($this->walletRow($this->walletId))->toBe($this->snapshot)
        ->and($this->other->wallets()->count())->toBe(0);
});

it('throws for non-fillable attributes passed to update() in strict mode', function (): void {
    $this->withStrictModels(function (): void {
        expect(fn () => $this->wallet->update(['holder_id' => $this->other->getKey()]))
            ->toThrow(MassAssignmentException::class);
    });

    expect($this->walletRow($this->walletId))->toBe($this->snapshot);
});

it('refuses to change protected attributes on the model', function (string $column, mixed $value, Closure $save): void {
    $this->wallet->forceFill([$column => $value]);

    expect(fn () => $save($this->wallet))->toThrow(ImmutableWallet::class);

    expect($this->walletRow($this->walletId))->toBe($this->snapshot);
})->with([
    'balance' => ['balance', 999],
    'negative balance' => ['balance', -1],
    'holder_id' => ['holder_id', 2],
    'holder_type' => ['holder_type', 'merchant'],
    'name' => ['name', 'renamed'],
    'currency' => ['currency', 'USD'],
    'id' => ['id', 999],
])->with([
    'save' => fn (Wallet $w) => $w->save(),
    'saveQuietly' => fn (Wallet $w) => $w->saveQuietly(),
    'withoutEvents' => fn (Wallet $w) => Model::withoutEvents(fn () => $w->save()),
    'saveOrFail' => fn (Wallet $w) => $w->saveOrFail(),
    'push' => fn (Wallet $w) => $w->push(),
]);

it('refuses protected changes even when models are unguarded', function (): void {
    expect(fn () => Model::unguarded(fn () => $this->wallet->update(['balance' => 999])))
        ->toThrow(ImmutableWallet::class);

    expect($this->walletRow($this->walletId))->toBe($this->snapshot);
});

it('refuses to clone a funded wallet into a new one', function (): void {
    DB::table($this->walletsTable())->where('id', $this->walletId)->update(['balance' => 500]);

    $clone = Wallet::findOrFail($this->walletId)->replicate();
    $clone->forceFill(['name' => 'clone']);

    expect(fn () => $clone->save())->toThrow(ImmutableWallet::class);

    expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount);
});

it('refuses a balance change even when an updating listener sneaks it in', function (): void {
    Wallet::updating(function (Wallet $wallet): void {
        $wallet->balance = 999;
    });

    $this->wallet->update(['meta' => ['x' => 1]]);
})->throws(ImmutableWallet::class);

it('refuses a starting balance even when a creating listener sneaks it in', function (): void {
    Wallet::creating(function (Wallet $wallet): void {
        $wallet->balance = 999;
    });

    try {
        $this->holder->wallet('sneaky');
    } finally {
        expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount);
    }
})->throws(ImmutableWallet::class);

it('refuses to increment or decrement protected columns on the model', function (Closure $write): void {
    expect(fn () => $write($this->wallet))->toThrow(ImmutableWallet::class);

    expect($this->walletRow($this->walletId))->toBe($this->snapshot);
})->with([
    'increment' => fn (Wallet $w) => $w->increment('balance', 500),
    'decrement' => fn (Wallet $w) => $w->decrement('balance', 500),
    'incrementQuietly' => fn (Wallet $w) => $w->incrementQuietly('balance', 500),
    'extra columns' => fn (Wallet $w) => $w->increment('min_balance', 1, ['balance' => 999]),
]);

it('refuses to create a wallet with a starting balance', function (Closure $create): void {
    expect(fn () => $create($this))->toThrow(ImmutableWallet::class);

    expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount);
})->with([
    'new model' => fn ($test) => (new Wallet)->forceFill([
        'holder_type' => 'user', 'holder_id' => $test->holder->getKey(),
        'name' => 'rich', 'currency' => 'EGP', 'balance' => 5,
    ])->save(),
    'new model quietly' => fn ($test) => (new Wallet)->forceFill([
        'holder_type' => 'user', 'holder_id' => $test->holder->getKey(),
        'name' => 'rich', 'currency' => 'EGP', 'balance' => 5,
    ])->saveQuietly(),
    'builder forceCreate' => fn ($test) => Wallet::query()->forceCreate([
        'holder_type' => 'user', 'holder_id' => $test->holder->getKey(),
        'name' => 'rich', 'currency' => 'EGP', 'balance' => 999,
    ]),
    'static forceCreate' => fn ($test) => Wallet::forceCreate([
        'holder_type' => 'user', 'holder_id' => $test->holder->getKey(),
        'name' => 'rich', 'currency' => 'EGP', 'balance' => 999,
    ]),
    'forceCreateQuietly' => fn ($test) => Wallet::query()->forceCreateQuietly([
        'holder_type' => 'user', 'holder_id' => $test->holder->getKey(),
        'name' => 'rich', 'currency' => 'EGP',
    ]),
    'relation forceCreate' => fn ($test) => $test->holder->wallets()->forceCreate([
        'name' => 'rich', 'currency' => 'EGP', 'balance' => 999,
    ]),
]);

it('ignores a balance passed as pending relation attributes', function (): void {
    // The model default (balance 0) wins over withAttributes(): the pending
    // balance is dropped, never written.
    $wallet = $this->holder->wallets()
        ->withAttributes(['name' => 'rich', 'currency' => 'EGP', 'balance' => 5])
        ->create();

    expect($wallet->balance)->toBe(0)
        ->and((int) $this->walletRow($wallet->id)['balance'])->toBe(0);
});

it('validates name and currency on every creation path', function (): void {
    expect(fn () => $this->holder->wallets()
        ->withAttributes(['name' => 'lower', 'currency' => 'egp'])->create())
        ->toThrow(InvalidWalletAttribute::class);

    expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount);
});

it('refuses query-level writes to protected columns', function (Closure $write): void {
    expect(fn () => $write())->toThrow(ImmutableWallet::class);

    expect($this->walletRow($this->walletId))->toBe($this->snapshot);
})->with([
    'update balance' => fn () => Wallet::query()->update(['balance' => 999]),
    'update qualified balance' => fn () => Wallet::query()->update(['wallets.balance' => 999]),
    'update quoted balance' => fn () => Wallet::query()->update(['"wallets"."balance"' => 999]),
    'update uppercase balance' => fn () => Wallet::query()->update(['BALANCE' => 999]),
    'update holder_id' => fn () => Wallet::query()->update(['holder_id' => 2]),
    'update holder_type' => fn () => Wallet::query()->update(['holder_type' => 'merchant']),
    'update name' => fn () => Wallet::query()->update(['name' => 'x']),
    'update currency' => fn () => Wallet::query()->update(['currency' => 'USD']),
    'update mixed with meta' => fn () => Wallet::query()->update(['meta' => '{}', 'balance' => 1]),
    'static update' => fn () => Wallet::where('id', '>', 0)->update(['balance' => 999]),
    'relation update' => fn () => test()->holder->wallets()->update(['balance' => 999]),
    'increment' => fn () => Wallet::query()->increment('balance', 999),
    'increment qualified' => fn () => Wallet::query()->increment('wallets.balance', 999),
    'increment expression' => fn () => Wallet::query()->increment(DB::raw('balance'), 999),
    'decrement' => fn () => Wallet::query()->decrement('balance', 999),
    'increment extra' => fn () => Wallet::query()->increment('min_balance', 1, ['balance' => 999]),
    'incrementEach' => fn () => Wallet::query()->incrementEach(['balance' => 999]),
    'incrementEach qualified' => fn () => Wallet::query()->incrementEach(['min_balance' => 1, 'wallets.balance' => 999]),
    'decrementEach' => fn () => Wallet::query()->decrementEach(['balance' => 999]),
    'touch balance' => fn () => Wallet::query()->touch('balance'),
    'updateFrom' => fn () => Wallet::query()->updateFrom(['balance' => 999]),
]);

it('refuses bulk inserts and upserts', function (Closure $write): void {
    expect(fn () => $write())->toThrow(ImmutableWallet::class);

    expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount)
        ->and($this->walletRow($this->walletId))->toBe($this->snapshot);
})->with(function (): array {
    $row = fn (): array => [
        'holder_type' => 'user', 'holder_id' => 1, 'name' => 'forged',
        'currency' => 'EGP', 'balance' => 999, 'min_balance' => 0, 'status' => 'active',
    ];

    return [
        'insert' => [fn () => Wallet::query()->insert($row())],
        'static insert' => [fn () => Wallet::insert($row())],
        'insertGetId' => [fn () => Wallet::query()->insertGetId($row())],
        'insertOrIgnore' => [fn () => Wallet::query()->insertOrIgnore($row())],
        'insertUsing' => [fn () => Wallet::query()->insertUsing(['name'], DB::table('users')->select('name'))],
        'insertOrIgnoreUsing' => [fn () => Wallet::query()->insertOrIgnoreUsing(['name'], DB::table('users')->select('name'))],
        'fillAndInsert' => [fn () => Wallet::query()->fillAndInsert([$row()])],
        'fillAndInsertGetId' => [fn () => Wallet::query()->fillAndInsertGetId($row())],
        'upsert new' => [fn () => Wallet::query()->upsert([$row()], ['holder_type', 'holder_id', 'name'], ['balance'])],
        'upsert existing' => [fn () => Wallet::query()->upsert(
            [array_merge($row(), ['holder_id' => test()->holder->getKey(), 'name' => 'default'])],
            ['holder_type', 'holder_id', 'name'],
            ['balance'],
        )],
        'relation upsert' => [fn () => test()->holder->wallets()->upsert(
            [['name' => 'default', 'currency' => 'EGP', 'balance' => 999]],
            ['holder_type', 'holder_id', 'name'],
            ['balance'],
        )],
        'updateOrInsert' => [fn () => Wallet::query()->updateOrInsert(['name' => 'default'], ['balance' => 999])],
    ];
});

it('refuses to delete wallets', function (Closure $delete): void {
    expect(fn () => $delete($this->wallet))->toThrow(ImmutableWallet::class);

    expect(DB::table($this->walletsTable())->count())->toBe($this->walletCount)
        ->and($this->walletRow($this->walletId))->toBe($this->snapshot);
})->with([
    'model delete' => fn (Wallet $w) => $w->delete(),
    'model deleteQuietly' => fn (Wallet $w) => $w->deleteQuietly(),
    'model deleteOrFail' => fn (Wallet $w) => $w->deleteOrFail(),
    'model forceDelete' => fn (Wallet $w) => $w->forceDelete(),
    'delete without events' => fn (Wallet $w) => Model::withoutEvents(fn () => $w->delete()),
    'destroy' => fn (Wallet $w) => Wallet::destroy($w->id),
    'forceDestroy' => fn (Wallet $w) => Wallet::forceDestroy($w->id),
    'builder delete' => fn (Wallet $w) => Wallet::query()->whereKey($w->id)->delete(),
    'builder forceDelete' => fn (Wallet $w) => Wallet::query()->forceDelete(),
    'relation delete' => fn (Wallet $w) => test()->holder->wallets()->delete(),
    'truncate' => fn (Wallet $w) => Wallet::query()->truncate(),
    'static truncate' => fn (Wallet $w) => Wallet::truncate(),
]);

it('still allows query-level updates of harmless columns', function (): void {
    Wallet::query()->whereKey($this->wallet->id)->update(['meta' => json_encode(['a' => 1])]);
    Wallet::query()->whereKey($this->wallet->id)->update(['wallets.min_balance' => -10]);
    Wallet::query()->whereKey($this->wallet->id)->update(['status' => WalletStatus::Frozen->value]);
    Wallet::query()->whereKey($this->wallet->id)->increment('min_balance', 3);

    $row = $this->walletRow($this->walletId);

    expect(json_decode((string) $row['meta'], true))->toBe(['a' => 1])
        ->and((int) $row['min_balance'])->toBe(-7)
        ->and($row['status'])->toBe('frozen')
        ->and((int) $row['balance'])->toBe(0);
});

it('still allows json path updates on meta', function (): void {
    $this->wallet->update(['meta' => ['a' => 1]]);

    Wallet::query()->whereKey($this->wallet->id)->update(['meta->b' => 2]);

    expect(Wallet::findOrFail($this->wallet->id)->meta)->toBe(['a' => 1, 'b' => 2]);
});

it('still allows reads', function (): void {
    expect(Wallet::query()->whereKey($this->wallet->id)->value('balance'))->toBe(0)
        ->and(Wallet::query()->count())->toBe($this->walletCount)
        ->and(Wallet::query()->sum('balance'))->toEqual(0)
        ->and(Wallet::query()->where('holder_type', 'user')->exists())->toBeTrue();
});
