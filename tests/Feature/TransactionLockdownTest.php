<?php

declare(strict_types=1);

use Anabil\Wallet\Database\WalletTransactionBuilder;
use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\Tests\TestCase;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
 * Ledger rows are inserted with DB::table() here, the way the future ledger
 * writer will; everything written through Eloquent must be refused and the
 * table left exactly as it was.
 */

beforeEach(function (): void {
    $this->holder = User::create(['name' => 'Ada']);
    $this->wallet = $this->holder->wallet();
    $this->transactionId = $this->insertTransactionRow($this->wallet->id, ['meta' => json_encode(['n' => 1])]);
    $this->transaction = WalletTransaction::findOrFail($this->transactionId);
    $this->snapshot = $this->transactionRows();
});

it('reads its table name from config and uses the transaction builder', function (): void {
    config(['wallet.tables.transactions' => 'custom_ledger']);

    expect((new WalletTransaction)->getTable())->toBe('custom_ledger')
        ->and(WalletTransaction::query())->toBeInstanceOf(WalletTransactionBuilder::class)
        ->and((new WalletTransaction)->getFillable())->toBe([]);
});

it('casts its attributes and has no updated_at', function (): void {
    expect($this->transaction->amount)->toBe(100)
        ->and($this->transaction->balance_after)->toBe(100)
        ->and($this->transaction->meta)->toBe(['n' => 1])
        ->and($this->transaction->created_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($this->transaction->getUpdatedAtColumn())->toBeNull();
});

it('knows credits from debits', function (): void {
    $debit = WalletTransaction::findOrFail($this->insertTransactionRow($this->wallet->id, ['amount' => -40, 'balance_after' => 60]));

    expect($this->transaction->isCredit())->toBeTrue()
        ->and($this->transaction->isDebit())->toBeFalse()
        ->and($debit->isCredit())->toBeFalse()
        ->and($debit->isDebit())->toBeTrue();
});

it('resolves its relations', function (): void {
    $admin = Admin::create(['name' => 'Root']);
    $reference = User::create(['name' => 'Order owner']);

    $reversalId = $this->insertTransactionRow($this->wallet->id, [
        'type' => 'reversal',
        'amount' => -100,
        'balance_after' => 0,
        'reverses_id' => $this->transactionId,
        'reference_type' => 'user',
        'reference_id' => $reference->getKey(),
        'performed_by_type' => 'admin',
        'performed_by_id' => $admin->getKey(),
    ]);

    $reversal = WalletTransaction::findOrFail($reversalId);
    $original = WalletTransaction::findOrFail($this->transactionId);

    expect($reversal->wallet)->toBeInstanceOf(Wallet::class)
        ->and($reversal->wallet?->id)->toBe($this->wallet->id)
        ->and($reversal->reference)->toBeInstanceOf(User::class)
        ->and($reversal->reference?->getKey())->toBe($reference->getKey())
        ->and($reversal->performedBy)->toBeInstanceOf(Admin::class)
        ->and($reversal->performedBy?->getKey())->toBe($admin->getKey())
        ->and($reversal->reverses?->id)->toBe($this->transactionId)
        ->and($original->reversal?->id)->toBe($reversalId)
        ->and($original->reverses)->toBeNull()
        ->and($original->reference)->toBeNull()
        ->and($original->performedBy)->toBeNull()
        ->and($this->wallet->transactions()->count())->toBe(2);
});

it('refuses to create transactions through Eloquent', function (Closure $create): void {
    expect(fn () => $create($this->wallet->id))->toThrow(ImmutableTransaction::class);

    expect($this->transactionRows())->toBe($this->snapshot);
})->with([
    'new + save' => fn (int $id) => (new WalletTransaction)->forceFill(TestCase::ledgerAttributes($id))->save(),
    'new + saveQuietly' => fn (int $id) => (new WalletTransaction)->forceFill(TestCase::ledgerAttributes($id))->saveQuietly(),
    'new + save without events' => fn (int $id) => Model::withoutEvents(
        fn () => (new WalletTransaction)->forceFill(TestCase::ledgerAttributes($id))->save()
    ),
    'create' => fn (int $id) => WalletTransaction::create(TestCase::ledgerAttributes($id)),
    'builder create' => fn (int $id) => WalletTransaction::query()->create(TestCase::ledgerAttributes($id)),
    'createQuietly' => fn (int $id) => WalletTransaction::createQuietly(TestCase::ledgerAttributes($id)),
    'forceCreate' => fn (int $id) => WalletTransaction::forceCreate(TestCase::ledgerAttributes($id)),
    'forceCreateQuietly' => fn (int $id) => WalletTransaction::query()->forceCreateQuietly(TestCase::ledgerAttributes($id)),
    'firstOrCreate' => fn (int $id) => WalletTransaction::firstOrCreate(['idempotency_key' => 'x'], TestCase::ledgerAttributes($id)),
    'createOrFirst' => fn (int $id) => WalletTransaction::createOrFirst(['idempotency_key' => 'x'], TestCase::ledgerAttributes($id)),
    'updateOrCreate' => fn (int $id) => WalletTransaction::updateOrCreate(['idempotency_key' => 'x'], TestCase::ledgerAttributes($id)),
    'relation forceCreate' => fn (int $id) => Wallet::findOrFail($id)->transactions()->forceCreate(TestCase::ledgerAttributes($id)),
    'relation save' => fn (int $id) => Wallet::findOrFail($id)->transactions()->save(
        (new WalletTransaction)->forceFill(TestCase::ledgerAttributes($id))
    ),
    'insert' => fn (int $id) => WalletTransaction::query()->insert(TestCase::ledgerAttributes($id)),
    'static insert' => fn (int $id) => WalletTransaction::insert(TestCase::ledgerAttributes($id)),
    'insertGetId' => fn (int $id) => WalletTransaction::query()->insertGetId(TestCase::ledgerAttributes($id)),
    'insertOrIgnore' => fn (int $id) => WalletTransaction::query()->insertOrIgnore(TestCase::ledgerAttributes($id)),
    'insertUsing' => fn (int $id) => WalletTransaction::query()->insertUsing(['type'], DB::table('users')->select('name')),
    'insertOrIgnoreUsing' => fn (int $id) => WalletTransaction::query()->insertOrIgnoreUsing(['type'], DB::table('users')->select('name')),
    'fillAndInsert' => fn (int $id) => WalletTransaction::query()->fillAndInsert([TestCase::ledgerAttributes($id)]),
    'upsert' => fn (int $id) => WalletTransaction::query()->upsert([TestCase::ledgerAttributes($id)], ['id'], ['amount']),
    'updateOrInsert' => fn (int $id) => WalletTransaction::query()->updateOrInsert(['id' => 1], ['amount' => 999]),
]);

it('refuses mass assignment before reaching the database', function (Closure $write): void {
    // Nothing is fillable, so these fail even earlier than the ledger guard.
    expect(fn () => $write($this->transaction))->toThrow(MassAssignmentException::class);

    expect($this->transactionRows())->toBe($this->snapshot);
})->with([
    'model update' => fn (WalletTransaction $t) => $t->update(['amount' => 999]),
    'relation create' => fn (WalletTransaction $t) => Wallet::findOrFail($t->wallet_id)->transactions()->create(['type' => 'deposit']),
]);

it('refuses to change existing transactions', function (Closure $write): void {
    expect(fn () => $write($this->transaction))->toThrow(ImmutableTransaction::class);

    expect($this->transactionRows())->toBe($this->snapshot);
})->with([
    'change + save' => function (WalletTransaction $t) {
        $t->amount = 999;

        return $t->save();
    },
    'forceFill + saveQuietly' => fn (WalletTransaction $t) => $t->forceFill(['balance_after' => 999])->saveQuietly(),
    'save without events' => fn (WalletTransaction $t) => Model::withoutEvents(
        fn () => $t->forceFill(['meta' => ['forged' => true]])->save()
    ),
    'model increment' => fn (WalletTransaction $t) => $t->increment('amount', 5),
    'model decrementQuietly' => fn (WalletTransaction $t) => $t->decrementQuietly('amount', 5),
    'builder update' => fn (WalletTransaction $t) => WalletTransaction::query()->update(['amount' => 999]),
    'builder updateFrom' => fn (WalletTransaction $t) => WalletTransaction::query()->updateFrom(['amount' => 999]),
    'builder update meta' => fn (WalletTransaction $t) => WalletTransaction::query()->whereKey($t->id)->update(['description' => 'edited']),
    'relation update' => fn (WalletTransaction $t) => Wallet::findOrFail($t->wallet_id)->transactions()->update(['amount' => 999]),
    'builder increment' => fn (WalletTransaction $t) => WalletTransaction::query()->increment('amount'),
    'builder decrement' => fn (WalletTransaction $t) => WalletTransaction::query()->decrement('amount'),
    'builder incrementEach' => fn (WalletTransaction $t) => WalletTransaction::query()->incrementEach(['amount' => 1]),
    'builder decrementEach' => fn (WalletTransaction $t) => WalletTransaction::query()->decrementEach(['amount' => 1]),
    'builder touch' => fn (WalletTransaction $t) => WalletTransaction::query()->touch('created_at'),
]);

it('refuses to delete transactions', function (Closure $delete): void {
    expect(fn () => $delete($this->transaction))->toThrow(ImmutableTransaction::class);

    expect($this->transactionRows())->toBe($this->snapshot);
})->with([
    'model delete' => fn (WalletTransaction $t) => $t->delete(),
    'model deleteQuietly' => fn (WalletTransaction $t) => $t->deleteQuietly(),
    'model forceDelete' => fn (WalletTransaction $t) => $t->forceDelete(),
    'delete without events' => fn (WalletTransaction $t) => Model::withoutEvents(fn () => $t->delete()),
    'destroy' => fn (WalletTransaction $t) => WalletTransaction::destroy($t->id),
    'builder delete' => fn (WalletTransaction $t) => WalletTransaction::query()->whereKey($t->id)->delete(),
    'builder forceDelete' => fn (WalletTransaction $t) => WalletTransaction::query()->forceDelete(),
    'relation delete' => fn (WalletTransaction $t) => Wallet::findOrFail($t->wallet_id)->transactions()->delete(),
    'truncate' => fn (WalletTransaction $t) => WalletTransaction::query()->truncate(),
    'static truncate' => fn (WalletTransaction $t) => WalletTransaction::truncate(),
]);

it('explains what to do instead', function (): void {
    expect(fn () => $this->transaction->delete())
        ->toThrow(ImmutableTransaction::class, 'Record a reversing entry instead');
});

it('still allows reads', function (): void {
    expect(WalletTransaction::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->sum('amount'))->toEqual(100)
        ->and(WalletTransaction::query()->where('wallet_id', $this->wallet->id)->pluck('id')->all())->toBe([$this->transactionId]);
});
