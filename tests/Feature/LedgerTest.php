<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
    $this->wallet = $this->user->wallet();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('deposits: changes the balance and writes one ledger row', function (): void {
    Carbon::setTestNow('2026-01-02 03:04:05');

    $tx = $this->wallet->deposit(150);

    expect($this->wallet->balance)->toBe('150.00')
        ->and($this->wallet->balanceInMinor())->toBe(150_00)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(150_00)
        ->and($tx)->toBeInstanceOf(WalletTransaction::class)
        ->and($tx->exists)->toBeTrue()
        ->and($tx->wasRecentlyCreated)->toBeTrue()
        ->and($tx->isDirty())->toBeFalse()
        ->and($tx->id)->toBeInt()
        ->and($tx->amount)->toBe('150.00')
        ->and($tx->balance_after)->toBe('150.00')
        ->and($tx->amountInMinor())->toBe(150_00)
        ->and($tx->balanceAfterInMinor())->toBe(150_00)
        ->and($tx->type)->toBe(CoreType::Deposit)
        ->and($tx->created_at?->toDateTimeString())->toBe('2026-01-02 03:04:05');

    $rows = $this->transactionRows();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]['id'])->toBe($tx->id)
        ->and((int) $rows[0]['wallet_id'])->toBe($this->wallet->id)
        ->and($rows[0]['type'])->toBe('deposit')
        ->and((int) $rows[0]['amount'])->toBe(150_00)
        ->and((int) $rows[0]['balance_after'])->toBe(150_00)
        ->and((string) $rows[0]['created_at'])->toStartWith('2026-01-02 03:04:05')
        ->and($rows[0]['meta'])->toBeNull()
        ->and($rows[0]['description'])->toBeNull()
        ->and($rows[0]['reference_type'])->toBeNull()
        ->and($rows[0]['performed_by_type'])->toBeNull()
        ->and($rows[0]['idempotency_key'])->toBeNull()
        ->and($rows[0]['group_uuid'])->toBeNull()
        ->and($rows[0]['reverses_id'])->toBeNull();
});

it('withdraws: records a negative amount', function (): void {
    $this->wallet->deposit(100);

    $tx = $this->wallet->withdraw(30);

    expect($this->wallet->balance)->toBe('70.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(70_00)
        ->and($tx->amount)->toBe('-30.00')
        ->and($tx->amountInMinor())->toBe(-30_00)
        ->and($tx->balance_after)->toBe('70.00')
        ->and($tx->type)->toBe(CoreType::Withdraw)
        ->and($tx->getRawOriginal('type'))->toBe('withdraw')
        ->and($tx->isDebit())->toBeTrue()
        ->and($this->transactionRows())->toHaveCount(2);
});

it('touches the wallet updated_at and leaves the instance clean', function (): void {
    Carbon::setTestNow('2026-03-01 10:00:00');

    $this->wallet->deposit(1);

    expect($this->wallet->updated_at?->toDateTimeString())->toBe('2026-03-01 10:00:00')
        ->and((string) $this->walletRow($this->wallet->id)['updated_at'])->toStartWith('2026-03-01 10:00:00')
        ->and($this->wallet->isDirty())->toBeFalse();
});

it('returns the entry with its relations preloaded', function (): void {
    $admin = Admin::create(['name' => 'Root']);
    $order = Order::create();

    $tx = $this->wallet->deposit(1, reference: $order, performedBy: $admin);

    $queries = $this->queriesDuring(function () use ($tx, $order, $admin): void {
        expect($tx->wallet)->toBe($this->wallet)
            ->and($tx->reference)->toBe($order)
            ->and($tx->performedBy)->toBe($admin)
            ->and($tx->amount)->toBe('1.00');
    });

    $plain = $this->wallet->deposit(1);

    $queries = [...$queries, ...$this->queriesDuring(function () use ($plain): void {
        expect($plain->reference)->toBeNull()
            ->and($plain->performedBy)->toBeNull();
    })];

    expect($queries)->toBe([]);
});

it('runs a fixed, small number of queries per write', function (): void {
    $queries = $this->queriesDuring(fn () => $this->wallet->deposit(1));

    // UPDATE, SELECT balance, INSERT (transaction statements are not queries).
    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toStartWith('update')
        ->and($queries[1])->toStartWith('select')
        ->and($queries[2])->toStartWith('insert');
});

it('keeps the ledger sum and the balance_after chain consistent', function (): void {
    $this->wallet->setMinBalance(-50);

    foreach (['100', '-30', '5.00', '-120', '0.01', '44.99', '-0.01'] as $amount) {
        str_starts_with($amount, '-') ? $this->wallet->withdraw(ltrim($amount, '-')) : $this->wallet->deposit($amount);
    }

    $balance = (int) $this->walletRow($this->wallet->id)['balance'];
    $running = 0;

    foreach ($this->transactionRows() as $row) {
        $running += (int) $row['amount'];

        expect((int) $row['balance_after'])->toBe($running);
    }

    expect($this->ledgerSum($this->wallet->id))->toBe($balance)
        ->and($balance)->toBe(-1)
        ->and($running)->toBe($balance)
        ->and($this->wallet->balanceInMinor())->toBe($balance)
        ->and($this->wallet->balance)->toBe('-0.01');
});

it('rejects non-positive amounts', function (string $method, int|float|string $amount): void {
    expect(fn () => $this->wallet->{$method}($amount))->toThrow(InvalidAmount::class);

    expect($this->transactionRows())->toBe([]);
})->with([
    'deposit 0' => ['deposit', 0],
    'deposit negative' => ['deposit', -100],
    'withdraw 0' => ['withdraw', 0],
    'withdraw negative' => ['withdraw', -100],
    'deposit zero string' => ['deposit', '0.00'],
    'withdraw negative string' => ['withdraw', '-0.01'],
    'deposit zero float' => ['deposit', 0.0],
]);

it('refuses a withdrawal beyond the floor without writing anything', function (): void {
    $this->wallet->deposit(100);
    $snapshot = $this->transactionRows();

    try {
        $this->wallet->withdraw('100.01');
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->wallet)->toBe($this->wallet)
            ->and($e->requested)->toBe('100.01')
            ->and($e->balance)->toBe('100.00')
            ->and($e->minBalance)->toBe('0.00')
            ->and($e->available)->toBe('100.00')
            ->and($e->getMessage())->toContain('requested 100.01 EGP')
            ->and($e->getMessage())->toContain('available 100.00 EGP');
    }

    expect($this->transactionRows())->toBe($snapshot)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and($this->wallet->balance)->toBe('100.00');
});

it('allows withdrawing down to exactly the floor', function (): void {
    $this->wallet->deposit(100);
    $this->wallet->setMinBalance(20);

    $this->wallet->withdraw(80);

    expect($this->wallet->balance)->toBe('20.00')
        ->and(fn () => $this->wallet->withdraw('0.01'))->toThrow(InsufficientFunds::class);
});

it('lets a credit line be spent', function (): void {
    $wallet = Merchant::create(['name' => 'Shop'])->wallet();

    expect($wallet->min_balance)->toBe('-100.00');

    $wallet->withdraw(100);

    expect($wallet->balance)->toBe('-100.00')
        ->and($wallet->spendable())->toBe('0.00');

    try {
        $wallet->withdraw('0.01');
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->balance)->toBe('-100.00')
            ->and($e->minBalance)->toBe('-100.00')
            ->and($e->available)->toBe('0.00');
    }
});

it('accepts deposits into a wallet below its floor', function (): void {
    $this->wallet->setMinBalance(-5);
    $this->wallet->withdraw(3);
    $this->wallet->setMinBalance(0);

    $this->wallet->deposit(1);

    expect($this->wallet->balance)->toBe('-2.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(-200);

    try {
        $this->wallet->withdraw('0.01');
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->available)->toBe('0.00')
            ->and($e->balance)->toBe('-2.00')
            ->and($e->minBalance)->toBe('0.00');
    }
});

it('reports spendable and canWithdraw from the instance', function (): void {
    $this->wallet->deposit(50);
    $this->wallet->setMinBalance(-10);

    expect($this->wallet->spendable())->toBe('60.00')
        ->and($this->wallet->canWithdraw(60))->toBeTrue()
        ->and($this->wallet->canWithdraw('60.00'))->toBeTrue()
        ->and($this->wallet->canWithdraw(59.99))->toBeTrue()
        ->and($this->wallet->canWithdraw('60.01'))->toBeFalse()
        ->and($this->wallet->canWithdraw(0))->toBeFalse()
        ->and($this->wallet->canWithdraw(-1))->toBeFalse()
        ->and(fn () => $this->wallet->canWithdraw('1.001'))->toThrow(InvalidAmount::class);
});

it('checks the floor against the database, not a stale instance', function (): void {
    $this->wallet->deposit(100);

    $first = Wallet::findOrFail($this->wallet->id);
    $second = Wallet::findOrFail($this->wallet->id);

    $first->withdraw(70);

    // $second still believes the balance is 100.00.
    expect($second->balance)->toBe('100.00')
        ->and(fn () => $second->withdraw(70))->toThrow(InsufficientFunds::class);

    expect((int) $this->walletRow($this->wallet->id)['balance'])->toBe(30_00)
        ->and($this->ledgerSum($this->wallet->id))->toBe(30_00);
});

it('brings a stale instance up to date on deposit', function (): void {
    $stale = Wallet::findOrFail($this->wallet->id);

    $this->wallet->deposit(100);

    $tx = $stale->deposit(5);

    expect($tx->balance_after)->toBe('105.00')
        ->and($stale->balance)->toBe('105.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(105_00)
        ->and($stale->isDirty())->toBeFalse();
});

it('does not leave the balance dirty (v1 regression)', function (): void {
    $this->wallet->deposit(1);

    expect($this->wallet->isDirty())->toBeFalse();

    $this->wallet->update(['meta' => ['a' => 1]]);

    expect($this->wallet->meta)->toBe(['a' => 1])
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100)
        ->and(json_decode((string) $this->walletRow($this->wallet->id)['meta'], true))->toBe(['a' => 1]);
});

it('refuses a deposit that would overflow the balance', function (): void {
    $this->forceBalance($this->wallet->id, PHP_INT_MAX - 10);

    try {
        $this->wallet->deposit('0.11');
        $this->fail('Expected BalanceOverflow.');
    } catch (BalanceOverflow $e) {
        expect($e->getMessage())->toContain(Amount::format(PHP_INT_MAX - 10, 2).' EGP')
            ->and($e->amount)->toBe('0.11')
            ->and($e->balance)->toBe('92233720368547757.97')
            ->and($e->maximum)->toBe('92233720368547758.07');
    }

    expect($this->walletRow($this->wallet->id)['balance'])->toEqual(PHP_INT_MAX - 10)
        ->and($this->transactionRows())->toBe([]);

    $tx = $this->wallet->deposit('0.10');

    expect($tx->balanceAfterInMinor())->toBe(PHP_INT_MAX)
        ->and($tx->balance_after)->toBe('92233720368547758.07')
        ->and($this->wallet->balanceInMinor())->toBe(PHP_INT_MAX);
});

it('refuses to write to an unsaved or deleted wallet', function (): void {
    expect(fn () => (new Wallet)->deposit(100))->toThrow(WalletException::class, 'unsaved wallet');

    $ghost = Wallet::findOrFail($this->wallet->id);
    DB::table($this->walletsTable())->where('id', $ghost->id)->delete();

    expect(fn () => $ghost->deposit(100))->toThrow(WalletException::class, 'no longer exists')
        ->and(fn () => $ghost->withdraw(100))->toThrow(WalletException::class, 'no longer exists');
});

it('rolls back with an outer transaction and restores the instance', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $this->wallet->deposit(10);
    $before = $this->transactionRows();

    Carbon::setTestNow('2026-02-01 00:00:00');

    try {
        DB::transaction(function (): void {
            $this->wallet->deposit(5);
            $this->wallet->withdraw(2);

            expect($this->wallet->balance)->toBe('13.00');

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($this->transactionRows())->toBe($before)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(10_00)
        ->and($this->wallet->balance)->toBe('10.00')
        ->and($this->wallet->balanceInMinor())->toBe(10_00)
        ->and($this->wallet->updated_at?->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($this->wallet->isDirty())->toBeFalse();
});

it('keeps the new values when the outer transaction commits', function (): void {
    DB::transaction(fn () => $this->wallet->deposit(5));

    expect($this->wallet->balance)->toBe('5.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(5_00);
});

it('does not weaken the ledger lockdown', function (): void {
    $this->wallet->deposit(1);

    expect(fn () => WalletTransaction::query()->insert(TestCase::ledgerAttributes($this->wallet->id)))
        ->toThrow(ImmutableTransaction::class)
        ->and(fn () => WalletTransaction::query()->update(['amount' => 1]))
        ->toThrow(ImmutableTransaction::class)
        ->and(fn () => $this->wallet->transactions()->firstOrFail()->forceFill(['amount' => 1])->save())
        ->toThrow(ImmutableTransaction::class)
        ->and(fn () => $this->wallet->transactions()->firstOrFail()->delete())
        ->toThrow(ImmutableTransaction::class);

    expect($this->transactionRows())->toHaveCount(1);
});

it('proxies deposit and withdraw through the holder', function (): void {
    $this->user->deposit(40);
    $tx = $this->user->withdraw(15, description: 'ATM');

    $points = $this->user->wallet('points');
    $points->deposit('0.07');

    expect($this->user->balance())->toBe('25.00')
        ->and($tx->wallet_id)->toBe($this->wallet->id)
        ->and($tx->description)->toBe('ATM')
        ->and($this->user->balance('points'))->toBe('0.07')
        ->and($this->ledgerSum($points->id))->toBe(7);
});

it('creates the default wallet on first deposit through the holder', function (): void {
    $bob = User::create(['name' => 'Bob']);

    expect($bob->hasWallet())->toBeFalse();

    $bob->deposit(1);

    expect($bob->hasWallet())->toBeTrue()
        ->and($bob->balance())->toBe('1.00');
});
