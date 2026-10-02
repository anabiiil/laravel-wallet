<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\AppType;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\OrderPayment;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['wallet.types' => AppType::class]);

    // Everything in the same second, so only the id orders the entries.
    Carbon::setTestNow('2026-05-05 12:00:00');

    $this->admin = Admin::create(['name' => 'Root']);
    $this->otherAdmin = Admin::create(['name' => 'Other']);
    $this->order = Order::create();
    $this->otherOrder = Order::create();

    $this->wallet = User::create(['name' => 'Ada'])->wallet();
    $this->other = User::create(['name' => 'Bob'])->wallet();

    $this->ids = [
        'deposit' => $this->wallet->deposit(100, performedBy: $this->admin)->id,
        'purchase' => $this->wallet->withdraw(10, new OrderPayment($this->order))->id,
        'cashback' => $this->wallet->deposit(1, AppType::Cashback, reference: $this->order)->id,
        'bank' => $this->wallet->withdraw(20, 'bank_withdrawal', performedBy: $this->otherAdmin)->id,
        'other' => $this->other->deposit(5, reference: $this->otherOrder, performedBy: $this->admin)->id,
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('filters credits and debits', function (): void {
    expect(WalletTransaction::query()->credits()->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['deposit'], $this->ids['cashback'], $this->ids['other']])
        ->and(WalletTransaction::query()->debits()->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['purchase'], $this->ids['bank']])
        ->and($this->wallet->transactions()->credits()->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['deposit'], $this->ids['cashback']])
        ->and($this->wallet->transactions()->debits()->sum('amount'))->toEqual(-30_00);
});

it('filters by type', function (): void {
    expect(WalletTransaction::query()->ofType(CoreType::Deposit)->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['deposit'], $this->ids['other']])
        ->and($this->wallet->transactions()->ofType('deposit')->pluck('id')->all())
        ->toBe([$this->ids['deposit']])
        ->and($this->wallet->transactions()->ofType(AppType::Purchase, 'bank_withdrawal')->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['purchase'], $this->ids['bank']])
        ->and(WalletTransaction::query()->ofType(CoreType::Reversal, 'no_such_type')->count())->toBe(0)
        ->and(WalletTransaction::query()->ofType()->count())->toBe(0);
});

it('refuses unsupported enum cases in ofType', function (): void {
    expect(fn () => WalletTransaction::query()->ofType(WalletStatus::Active))
        ->toThrow(InvalidTransaction::class);
});

it('filters by reference', function (): void {
    expect(WalletTransaction::query()->whereReference($this->order)->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['purchase'], $this->ids['cashback']])
        ->and($this->wallet->transactions()->whereReference($this->otherOrder)->count())->toBe(0)
        ->and($this->other->transactions()->whereReference($this->otherOrder)->pluck('id')->all())
        ->toBe([$this->ids['other']]);
});

it('filters by who performed the entry', function (): void {
    expect(WalletTransaction::query()->wherePerformedBy($this->admin)->orderBy('id')->pluck('id')->all())
        ->toBe([$this->ids['deposit'], $this->ids['other']])
        ->and($this->wallet->transactions()->wherePerformedBy($this->admin)->pluck('id')->all())
        ->toBe([$this->ids['deposit']])
        ->and(WalletTransaction::wherePerformedBy($this->otherAdmin)->pluck('id')->all())
        ->toBe([$this->ids['bank']]);
});

it('does not confuse morph types that share an id', function (): void {
    // An admin and an order with the same key: only the type tells them apart.
    $id = max(Admin::query()->max('id'), Order::query()->max('id')) + 100;
    $admin = (new Admin)->forceFill(['id' => $id, 'name' => 'Twin']);
    $admin->save();
    $order = (new Order)->forceFill(['id' => $id]);
    $order->save();

    $tx = $this->wallet->deposit('0.01', reference: $order, performedBy: $admin);

    expect(WalletTransaction::query()->whereReference($order)->pluck('id')->all())->toBe([$tx->id])
        ->and(WalletTransaction::query()->whereReference($admin)->count())->toBe(0)
        ->and(WalletTransaction::query()->wherePerformedBy($admin)->pluck('id')->all())->toBe([$tx->id])
        ->and(WalletTransaction::query()->wherePerformedBy($order)->count())->toBe(0);
});

it('orders newest first by id', function (): void {
    expect($this->wallet->transactions()->latestFirst()->pluck('id')->all())
        ->toBe([$this->ids['bank'], $this->ids['cashback'], $this->ids['purchase'], $this->ids['deposit']])
        ->and(WalletTransaction::query()->latestFirst()->value('id'))->toBe($this->ids['other']);
});

it('chains the helpers', function (): void {
    expect($this->wallet->transactions()->debits()->ofType(AppType::Purchase)->whereReference($this->order)->latestFirst()->pluck('id')->all())
        ->toBe([$this->ids['purchase']]);
});
