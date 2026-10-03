<?php

declare(strict_types=1);

use Anabil\Wallet\Audit\IssueCode;
use Anabil\Wallet\Audit\Severity;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Events\TransactionReversed;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidReversal;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * A real listener is used instead of Event::fake(), so the after-commit
 * dispatch behaves as in production. The user's wallet (EGP, floor 0) is
 * created before the merchant's (EGP, credit line down to -100.00).
 */

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
    $this->merchant = Merchant::create(['name' => 'Shop']);
    $this->wallet = $this->user->wallet();
    $this->merchantWallet = $this->merchant->wallet();
    $this->events = new ArrayObject;

    Event::listen(TransactionRecorded::class, function (TransactionRecorded $event): void {
        $this->events[] = ['recorded', $event->transaction->id, DB::transactionLevel()];
    });

    Event::listen(TransactionReversed::class, function (TransactionReversed $event): void {
        $this->events[] = ['reversed', $event->original->id, $event->reversal->id, DB::transactionLevel()];
    });
});

/*
 * Single entries.
 */

it('reverses a deposit with a linked entry of the opposite amount', function (): void {
    $order = Order::create();
    $admin = Admin::create(['name' => 'Root']);
    $deposit = $this->wallet->deposit(100, reference: $order, description: 'Top up');
    $this->wallet->deposit('0.50');

    $reversal = $deposit->reverse(description: 'Charged twice', meta: ['ticket' => 7], performedBy: $admin);

    expect($reversal)->toBeInstanceOf(WalletTransaction::class)
        ->and($reversal->exists)->toBeTrue()
        ->and($reversal->wasRecentlyCreated)->toBeTrue()
        ->and($reversal->isDirty())->toBeFalse()
        ->and($reversal->type)->toBe(CoreType::Reversal)
        ->and($reversal->amount)->toBe('-100.00')
        ->and($reversal->balance_after)->toBe('0.50')
        ->and($reversal->wallet_id)->toBe($this->wallet->id)
        ->and($reversal->reverses_id)->toBe($deposit->id)
        ->and($reversal->reference_type)->toBe('order')
        ->and((string) $reversal->reference_id)->toBe((string) $order->id)
        ->and($reversal->description)->toBe('Charged twice')
        ->and($reversal->meta)->toBe(['ticket' => 7])
        ->and($reversal->performed_by_type)->toBe($admin->getMorphClass())
        ->and((string) $reversal->performed_by_id)->toBe((string) $admin->id)
        ->and($reversal->idempotency_key)->toBeNull()
        ->and($reversal->group_uuid)->toBeNull()
        ->and($reversal->isReversal())->toBeTrue()
        ->and($reversal->isDebit())->toBeTrue()
        ->and($deposit->isReversal())->toBeFalse()
        ->and($this->wallet->balance)->toBe('0.50')
        ->and($this->wallet->isDirty())->toBeFalse()
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(50)
        ->and($this->ledgerSum($this->wallet->id))->toBe(50);

    // Relations come preloaded from the request: no query needed.
    $queries = $this->queriesDuring(function () use ($reversal, $deposit, $order, $admin): void {
        expect($reversal->wallet)->toBe($this->wallet)
            ->and($reversal->reference)->toBe($order)
            ->and($reversal->performedBy)->toBe($admin)
            ->and($reversal->reverses)->toBe($deposit)
            ->and($deposit->reversal)->toBe($reversal)
            ->and($deposit->isReversed())->toBeTrue()
            ->and($reversal->isReversed())->toBeFalse();
    });

    expect($queries)->toHaveCount(1); // $reversal->reversal (nothing reverses a reversal)

    $row = $this->transactionRows()[2];

    expect($row['type'])->toBe('reversal')
        ->and((int) $row['amount'])->toBe(-100_00)
        ->and((int) $row['balance_after'])->toBe(50)
        ->and((int) $row['reverses_id'])->toBe($deposit->id)
        ->and($row['idempotency_hash'])->toBeNull();
});

it('reverses a withdrawal by crediting the money back', function (): void {
    $this->wallet->deposit(50);
    $withdrawal = $this->wallet->withdraw(20);

    $reversal = $withdrawal->reverse();

    expect($reversal->amount)->toBe('20.00')
        ->and($reversal->balance_after)->toBe('50.00')
        ->and($reversal->isCredit())->toBeTrue()
        ->and($reversal->description)->toBeNull()
        ->and($reversal->meta)->toBeNull()
        ->and($reversal->performedBy)->toBeNull()
        ->and($reversal->reference)->toBeNull()
        ->and($this->wallet->balance)->toBe('50.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(50_00);
});

it('nets the entries of a reference to zero once a purchase is reversed', function (): void {
    $order = Order::create();
    $this->wallet->deposit(100);
    $purchase = $this->wallet->withdraw(30, reference: $order, description: 'Order');

    expect((int) WalletTransaction::query()->whereReference($order)->sum('amount'))->toBe(-30_00);

    $purchase->reverse(description: 'Order cancelled');

    expect((int) WalletTransaction::query()->whereReference($order)->sum('amount'))->toBe(0)
        ->and(WalletTransaction::query()->whereReference($order)->count())->toBe(2)
        ->and($this->wallet->balance)->toBe('100.00');
});

it('loads the reversal and the reversed entry through their relations', function (): void {
    $deposit = $this->wallet->deposit(10);
    $kept = $this->wallet->deposit(5);
    $reversal = $deposit->reverse();

    $freshDeposit = WalletTransaction::query()->findOrFail($deposit->id);
    $freshReversal = WalletTransaction::query()->findOrFail($reversal->id);
    $freshKept = WalletTransaction::query()->findOrFail($kept->id);

    expect($freshDeposit->isReversed())->toBeTrue()
        ->and($freshDeposit->reversal?->id)->toBe($reversal->id)
        ->and($freshReversal->reverses?->id)->toBe($deposit->id)
        ->and($freshReversal->isReversal())->toBeTrue()
        ->and($freshReversal->type)->toBe(CoreType::Reversal)
        ->and($freshKept->isReversed())->toBeFalse()
        ->and($freshKept->reversal)->toBeNull()
        ->and($freshKept->reverses)->toBeNull()
        ->and($freshDeposit->counterpart())->toBeNull()
        ->and($freshReversal->counterpart())->toBeNull();

    // Eager loading avoids a query per entry.
    $entries = WalletTransaction::query()->with('reversal')->orderBy('id')->get();
    $queries = $this->queriesDuring(fn () => $entries->map->isReversed()->all());

    expect($queries)->toBe([])
        ->and($entries->map->isReversed()->all())->toBe([true, false, false]);
});

it('filters entries by reversal state', function (): void {
    $deposit = $this->wallet->deposit(10);
    $kept = $this->wallet->deposit(5);
    $withdrawal = $this->wallet->withdraw(1);
    $reversals = [$deposit->reverse()->id, $withdrawal->reverse()->id];

    expect(WalletTransaction::query()->reversals()->orderBy('id')->pluck('id')->all())->toBe($reversals)
        ->and(WalletTransaction::query()->whereReversed()->orderBy('id')->pluck('id')->all())->toBe([$deposit->id, $withdrawal->id])
        ->and(WalletTransaction::query()->whereNotReversed()->orderBy('id')->pluck('id')->all())->toBe([$kept->id, ...$reversals])
        ->and(WalletTransaction::query()->whereNotReversed()->ofType(CoreType::Deposit)->pluck('id')->all())->toBe([$kept->id])
        ->and($this->wallet->transactions()->whereReversed()->count())->toBe(2)
        ->and($this->wallet->transactions()->reversals()->credits()->pluck('id')->all())->toBe([$reversals[1]])
        ->and($this->merchantWallet->transactions()->reversals()->count())->toBe(0);
});

it('serialises a reversal and its reversed entry without looping over their relations', function (): void {
    $deposit = $this->wallet->deposit(10);
    $reversal = $deposit->reverse();

    expect(json_encode($reversal->toArray()))->toBeString()
        ->and(json_encode($deposit->toArray()))->toBeString();
});

it('reverses an entry of a KWD wallet with three decimal places', function (): void {
    $kwd = $this->user->createWallet('kwd', 'KWD');
    $deposit = $kwd->deposit('12.345');
    $kwd->deposit('0.005');

    $reversal = $deposit->reverse();

    expect($reversal->amount)->toBe('-12.345')
        ->and($reversal->balance_after)->toBe('0.005')
        ->and($kwd->balance)->toBe('0.005')
        ->and((int) $this->walletRow($kwd->id)['balance'])->toBe(5);
});

/*
 * The floor.
 */

it('refuses to reverse a deposit whose money was spent, without writing anything', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->withdraw(80);
    $before = $this->transactionRows();
    $this->events->exchangeArray([]);

    try {
        $deposit->reverse();
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->requested)->toBe('100.00')
            ->and($e->available)->toBe('20.00');
    }

    expect($this->transactionRows())->toBe($before)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(20_00)
        ->and($this->wallet->balance)->toBe('20.00')
        ->and($deposit->isReversed())->toBeFalse()
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('forces a reversal below the floor, which the audit reports as a warning only', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->withdraw(80);

    $reversal = $deposit->reverse(description: 'Chargeback', force: true);

    expect($reversal->amount)->toBe('-100.00')
        ->and($reversal->balance_after)->toBe('-80.00')
        ->and($this->wallet->balance)->toBe('-80.00')
        ->and($this->wallet->min_balance)->toBe('0.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(-80_00);

    $report = $this->auditor()->audit();

    expect($report->passed())->toBeTrue()
        ->and(array_map(fn ($issue) => $issue->code, $report->issues))->toBe([IssueCode::BelowFloor])
        ->and($report->issues[0]->walletId)->toBe($this->wallet->id);

    // The floor applies again to the next withdrawal.
    expect(fn () => $this->wallet->withdraw('0.01'))->toThrow(InsufficientFunds::class);
});

it('still refuses a forced reversal that would overflow the balance', function (): void {
    $this->wallet->deposit(50);
    $withdrawal = $this->wallet->withdraw(10);
    $this->forceBalance($this->wallet->id, PHP_INT_MAX - 5_00);

    expect(fn () => $withdrawal->reverse(force: true))->toThrow(BalanceOverflow::class)
        ->and($this->transactionRows())->toHaveCount(2);
});

/*
 * Transfers.
 */

it('reverses a whole transfer through its transfer_out leg', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30, description: 'Pay');
    $this->events->exchangeArray([]);

    $reversal = $transfer->out->reverse(description: 'Refund');
    $other = $reversal->counterpart();

    expect($reversal->type)->toBe(CoreType::Reversal)
        ->and($reversal->amount)->toBe('30.00')
        ->and($reversal->balance_after)->toBe('100.00')
        ->and($reversal->wallet_id)->toBe($this->wallet->id)
        ->and($reversal->reverses_id)->toBe($transfer->out->id)
        ->and($reversal->description)->toBe('Refund')
        ->and($reversal->group_uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($reversal->group_uuid)->not->toBe($transfer->group)
        ->and($other)->toBeInstanceOf(WalletTransaction::class)
        ->and($other?->type)->toBe(CoreType::Reversal)
        ->and($other?->amount)->toBe('-30.00')
        ->and($other?->balance_after)->toBe('0.00')
        ->and($other?->wallet_id)->toBe($this->merchantWallet->id)
        ->and($other?->reverses_id)->toBe($transfer->in->id)
        ->and($other?->description)->toBe('Refund')
        ->and($other?->group_uuid)->toBe($reversal->group_uuid)
        ->and($other?->counterpart()?->id)->toBe($reversal->id)
        ->and($reversal->id)->toBeLessThan((int) $other?->id)
        ->and($transfer->out->isReversed())->toBeTrue()
        ->and($transfer->in->isReversed())->toBeTrue()
        ->and($this->wallet->balance)->toBe('100.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(0)
        ->and($this->merchantWallet->refresh()->balance)->toBe('0.00')
        // Without the transaction level each event was dispatched at.
        ->and(array_map(fn (array $event): array => array_slice($event, 0, -1), $this->events->getArrayCopy()))->toBe([
            ['recorded', $reversal->id],
            ['recorded', $other?->id],
            ['reversed', $transfer->out->id, $reversal->id],
            ['reversed', $transfer->in->id, $other?->id],
        ]);

    $report = $this->auditor()->audit();

    expect($report->passed())->toBeTrue()
        ->and($report->issues)->toBe([])
        ->and($report->groupsChecked)->toBe(2);
});

it('reverses a whole transfer through its transfer_in leg', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $in = WalletTransaction::query()->findOrFail($transfer->in->id);

    $reversal = $in->reverse();
    $other = $reversal->counterpart();

    expect($reversal->amount)->toBe('-30.00')
        ->and($reversal->wallet_id)->toBe($this->merchantWallet->id)
        ->and($reversal->reverses_id)->toBe($transfer->in->id)
        ->and($reversal->wallet?->balance)->toBe('0.00')
        ->and($reversal->reverses)->toBe($in)
        ->and($other?->amount)->toBe('30.00')
        ->and($other?->wallet_id)->toBe($this->wallet->id)
        ->and($other?->reverses_id)->toBe($transfer->out->id)
        ->and($other?->id)->toBeLessThan($reversal->id)
        ->and($in->isReversed())->toBeTrue()
        ->and(WalletTransaction::query()->findOrFail($transfer->out->id)->isReversed())->toBeTrue()
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(0)
        ->and($this->auditor()->audit()->passed())->toBeTrue();
});

it('refuses to reverse a transfer whose recipient spent the money, unless forced', function (bool $viaOut): void {
    $friend = User::create(['name' => 'Bob'])->wallet();
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($friend, 30);
    $friend->withdraw(20);
    $before = $this->transactionRows();
    $leg = $viaOut ? $transfer->out : $transfer->in;

    expect(fn () => $leg->reverse())->toThrow(InsufficientFunds::class)
        ->and($this->transactionRows())->toBe($before)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(70_00)
        ->and((int) $this->walletRow($friend->id)['balance'])->toBe(10_00)
        ->and($this->wallet->balance)->toBe('70.00');

    $reversal = $leg->reverse(force: true);

    expect($reversal->reverses_id)->toBe($leg->id)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and((int) $this->walletRow($friend->id)['balance'])->toBe(-20_00)
        ->and($this->auditor()->audit()->passed())->toBeTrue();
})->with(['via the transfer_out leg' => true, 'via the transfer_in leg' => false]);

it('lets a credit-line recipient go down to its floor when a transfer is reversed', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $this->merchantWallet->withdraw(100); // -70.00, floor -100.00

    $transfer->in->reverse();

    expect($this->merchantWallet->balance)->toBe('-100.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00);
});

it('refuses to take a credit-line recipient past its floor unless forced', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $this->merchantWallet->withdraw(110); // -80.00, floor -100.00

    expect(fn () => $transfer->out->reverse())->toThrow(InsufficientFunds::class)
        ->and($this->transactionRows())->toHaveCount(4);

    $transfer->out->reverse(force: true);

    $report = $this->auditor()->audit();

    expect((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(-110_00)
        ->and($report->passed())->toBeTrue()
        ->and(array_map(fn ($issue) => $issue->code, $report->issues))->toBe([IssueCode::BelowFloor]);
});

it('reverses a transfer sent on a credit line', function (): void {
    $transfer = $this->merchantWallet->transferTo($this->wallet, 50); // merchant at -50.00

    $reversal = $transfer->out->reverse();

    expect($reversal->amount)->toBe('50.00')
        ->and($this->merchantWallet->balance)->toBe('0.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(0);
});

/*
 * Refusals.
 */

it('refuses to reverse an entry twice, exposing the existing reversal', function (): void {
    $deposit = $this->wallet->deposit(100);
    $reversal = $deposit->reverse();
    $before = $this->transactionRows();
    $this->events->exchangeArray([]);

    foreach ([$deposit, WalletTransaction::query()->findOrFail($deposit->id)] as $entry) {
        try {
            $entry->reverse();
            $this->fail('Expected InvalidReversal.');
        } catch (InvalidReversal $e) {
            expect($e->transaction?->id)->toBe($deposit->id)
                ->and($e->reversal?->id)->toBe($reversal->id)
                ->and($e->reversal?->amount)->toBe('-100.00')
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getMessage())->toContain('already been reversed');
        }
    }

    expect($this->transactionRows())->toBe($before)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('refuses to reverse a reversal', function (): void {
    $reversal = $this->wallet->deposit(100)->reverse();

    foreach ([$reversal, WalletTransaction::query()->findOrFail($reversal->id)] as $entry) {
        expect(fn () => $entry->reverse())->toThrow(InvalidReversal::class, 'is a reversal and cannot be reversed');
    }

    expect($this->transactionRows())->toHaveCount(2);
});

it('refuses to reverse an unsaved entry', function (): void {
    $queries = $this->queriesDuring(function (): void {
        expect(fn () => (new WalletTransaction)->reverse())->toThrow(InvalidReversal::class, 'has not been recorded');
    });

    expect($queries)->toBe([]);
});

it('validates the details and the key before writing', function (array $args): void {
    $deposit = $this->wallet->deposit(10);

    expect(fn () => $deposit->reverse(...$args))->toThrow(InvalidTransaction::class)
        ->and($this->transactionRows())->toHaveCount(1);
})->with([
    'description too long' => [['description' => str_repeat('x', 256)]],
    'meta not JSON' => [['meta' => ['bad' => "\xff"]]],
    'unsaved actor' => [['performedBy' => new Admin]],
    'empty key' => [['idempotencyKey' => ' ']],
]);

it('refuses to reverse a leg of an incomplete transfer', function (string $case): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $table = DB::table($this->transactionsTable());

    match ($case) {
        'counterpart moved to another group' => $table->where('id', $transfer->in->id)->update(['group_uuid' => (string) Str::uuid7()]),
        'counterpart amount differs' => $table->where('id', $transfer->in->id)->update(['amount' => 29_00]),
        'counterpart of the wrong type' => $table->where('id', $transfer->in->id)->update(['type' => 'transfer_out']),
        'extra transfer_in in the group' => $this->insertTransactionRow($this->merchantWallet->id, ['type' => 'transfer_in', 'amount' => 1, 'balance_after' => 30_01, 'group_uuid' => $transfer->group]),
        'no group' => $table->where('id', $transfer->out->id)->update(['group_uuid' => null]),
    };

    $before = $this->transactionRows();

    expect(fn () => WalletTransaction::query()->findOrFail($transfer->out->id)->reverse())
        ->toThrow(InvalidReversal::class, 'counterpart is missing or does not match')
        ->and($this->transactionRows())->toBe($before)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(70_00);
})->with([
    'counterpart moved to another group',
    'counterpart amount differs',
    'counterpart of the wrong type',
    'extra transfer_in in the group',
    'no group',
]);

it('refuses to reverse a transfer whose counterpart was already reversed', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $loose = $this->competingReversal($transfer->in);
    $before = $this->transactionRows();

    try {
        $transfer->out->reverse();
        $this->fail('Expected InvalidReversal.');
    } catch (InvalidReversal $e) {
        expect($e->transaction?->id)->toBe($transfer->in->id)
            ->and($e->reversal?->id)->toBe($loose);
    }

    expect($this->transactionRows())->toBe($before)
        ->and($transfer->out->isReversed())->toBeFalse();
});

/*
 * Idempotency.
 */

it('replays a reversal made with the same key', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(5);
    $first = $deposit->reverse(description: 'Refund', idempotencyKey: 'refund-1');
    $this->events->exchangeArray([]);

    $again = WalletTransaction::query()->findOrFail($deposit->id)->reverse(description: 'Refund', idempotencyKey: 'refund-1');

    expect($first->idempotency_key)->toBe('refund-1')
        ->and($first->idempotency_hash)->toBe($this->reversalFingerprint($deposit, 'Refund'))
        ->and($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->amount)->toBe('-100.00')
        ->and($again->reverses_id)->toBe($deposit->id)
        ->and($this->transactionRows())->toHaveCount(3)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(5_00)
        ->and($this->events->getArrayCopy())->toBe([]);

    // The original instance replays too, and its wallet instance stays as is.
    $balanceBefore = $this->wallet->balance;

    expect($deposit->reverse(description: 'Refund', idempotencyKey: 'refund-1')->id)->toBe($first->id)
        ->and($this->wallet->balance)->toBe($balanceBefore);
});

it('refuses to reuse a reversal key for a different operation', function (string $change): void {
    $deposit = $this->wallet->deposit(100);
    $other = $this->wallet->deposit(7);
    $deposit->reverse(description: 'Refund', idempotencyKey: 'k');
    $before = $this->transactionRows();

    $call = match ($change) {
        'description' => fn () => $deposit->reverse(description: 'Other', idempotencyKey: 'k'),
        'force' => fn () => $deposit->reverse(description: 'Refund', force: true, idempotencyKey: 'k'),
        'meta' => fn () => $deposit->reverse(description: 'Refund', meta: ['a' => 1], idempotencyKey: 'k'),
        'another entry' => fn () => $other->reverse(description: 'Refund', idempotencyKey: 'k'),
    };

    expect($call)->toThrow(IdempotencyConflict::class)
        ->and($this->transactionRows())->toBe($before);
})->with(['description', 'force', 'meta', 'another entry']);

it('refuses a reversal key already used by a deposit on the wallet', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(1, idempotencyKey: 'used');

    expect(fn () => $deposit->reverse(idempotencyKey: 'used'))->toThrow(IdempotencyConflict::class)
        ->and($deposit->isReversed())->toBeFalse()
        ->and($this->transactionRows())->toHaveCount(2);
});

it('stores the key of a transfer reversal on the original sender\'s reversal only', function (bool $viaOut): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $leg = $viaOut ? $transfer->out : $transfer->in;

    $reversal = $leg->reverse(description: 'Undo', idempotencyKey: 'undo-1');

    $keyed = DB::table($this->transactionsTable())->where('idempotency_key', 'undo-1')->get();

    expect($keyed)->toHaveCount(1)
        ->and((int) $keyed[0]->wallet_id)->toBe($this->wallet->id)
        ->and((int) $keyed[0]->reverses_id)->toBe($transfer->out->id)
        ->and($keyed[0]->idempotency_hash)->toBe($this->reversalFingerprint($transfer->out, 'Undo'))
        ->and($reversal->reverses_id)->toBe($leg->id);

    $this->events->exchangeArray([]);

    // Replays through either leg return that leg's reversal.
    $outReplay = WalletTransaction::query()->findOrFail($transfer->out->id)->reverse(description: 'Undo', idempotencyKey: 'undo-1');
    $inReplay = WalletTransaction::query()->findOrFail($transfer->in->id)->reverse(description: 'Undo', idempotencyKey: 'undo-1');

    expect($outReplay->reverses_id)->toBe($transfer->out->id)
        ->and($outReplay->wasRecentlyCreated)->toBeFalse()
        ->and($inReplay->reverses_id)->toBe($transfer->in->id)
        ->and($inReplay->wasRecentlyCreated)->toBeFalse()
        ->and($inReplay->counterpart()?->id)->toBe($outReplay->id)
        ->and($this->transactionRows())->toHaveCount(5)
        ->and($this->events->getArrayCopy())->toBe([])
        // The recipient's wallet keeps the key free.
        ->and($this->merchantWallet->deposit(1, idempotencyKey: 'undo-1')->idempotency_key)->toBe('undo-1');
})->with(['via the transfer_out leg' => true, 'via the transfer_in leg' => false]);

it('does not consume the key when the reversal fails', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->withdraw(80);

    expect(fn () => $deposit->reverse(idempotencyKey: 'retry'))->toThrow(InsufficientFunds::class)
        ->and(DB::table($this->transactionsTable())->where('idempotency_key', 'retry')->count())->toBe(0);

    $this->wallet->deposit(80);
    $reversal = $deposit->reverse(idempotencyKey: 'retry');

    expect($reversal->idempotency_key)->toBe('retry')
        ->and($this->wallet->balance)->toBe('0.00');
});

/*
 * Races: a concurrent reversal commits between our check and our write.
 */

it('reports a reversal that won the race as already reversed', function (bool $spare): void {
    $deposit = $this->wallet->deposit(100);

    if ($spare) {
        // Enough money for both debits: ours then fails on reverses_id.
        $this->wallet->deposit(100);
    }

    $winner = null;
    $this->afterReversalCheck(function () use (&$winner, $deposit): void {
        $winner = $this->competingReversal($deposit);
    });
    $this->events->exchangeArray([]);
    $balance = $this->wallet->balance;

    try {
        $deposit->reverse();
        $this->fail('Expected InvalidReversal.');
    } catch (InvalidReversal $e) {
        expect($e->reversal?->id)->toBe($winner)
            ->and($e->transaction?->id)->toBe($deposit->id)
            ->and($e->getPrevious())->toBeInstanceOf($spare ? UniqueConstraintViolationException::class : InsufficientFunds::class);
    }

    expect(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->count())->toBe(1)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe($spare ? 100_00 : 0)
        ->and($this->ledgerSum($this->wallet->id))->toBe($spare ? 100_00 : 0)
        ->and($this->wallet->balance)->toBe($balance)
        ->and($this->events->getArrayCopy())->toBe([]);
})->with(['the debit still fits' => true, 'the winner took the money' => false]);

it('replays the winner of a race with a matching key', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(100);

    $winner = null;
    $this->afterReversalCheck(function () use (&$winner, $deposit): void {
        $winner = $this->competingReversal($deposit, 'race');
    });
    $this->events->exchangeArray([]);

    $reversal = $deposit->reverse(idempotencyKey: 'race');

    expect($reversal->id)->toBe($winner)
        ->and($reversal->wasRecentlyCreated)->toBeFalse()
        ->and($reversal->wallet)->toBe($this->wallet)
        ->and(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->count())->toBe(1)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('replays a keyed winner that commits between the key lookup and the reversal check', function (): void {
    $deposit = $this->wallet->deposit(100);

    $winner = null;
    $this->afterIdempotencyLookup(function () use (&$winner, $deposit): void {
        $winner = $this->competingReversal($deposit, 'race');
    });
    $this->events->exchangeArray([]);

    $reversal = $deposit->reverse(idempotencyKey: 'race');

    expect($reversal->id)->toBe($winner)
        ->and($reversal->wasRecentlyCreated)->toBeFalse()
        ->and(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->count())->toBe(1)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(0)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('throws a conflict when the race winner used the key for something else', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(100);

    $this->afterReversalCheck(function () use ($deposit): void {
        $this->competingReversal($deposit, 'race', $this->reversalFingerprint($deposit, 'Other'));
    });

    expect(fn () => $deposit->reverse(idempotencyKey: 'race'))->toThrow(IdempotencyConflict::class)
        ->and(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->count())->toBe(1)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00);
});

it('reports a transfer reversal that won the race as already reversed', function (): void {
    $this->wallet->deposit(100);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 30);
    $group = (string) Str::uuid7();
    $winners = [];

    $this->afterReversalCheck(function () use (&$winners, $transfer, $group): void {
        $winners[] = $this->competingReversal($transfer->out, group: $group);
        $winners[] = $this->competingReversal($transfer->in, group: $group);
    });

    try {
        $transfer->in->reverse();
        $this->fail('Expected InvalidReversal.');
    } catch (InvalidReversal $e) {
        expect($e->reversal?->id)->toBe($winners[0]);
    }

    expect(DB::table($this->transactionsTable())->where('type', 'reversal')->count())->toBe(2)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(0)
        ->and($this->auditor()->audit()->passed())->toBeTrue();
});

it('recovers from a lost race inside an outer transaction and keeps it usable', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(100);

    $this->afterReversalCheck(function () use ($deposit): void {
        $this->competingReversal($deposit);
    });

    $next = DB::transaction(function () use ($deposit) {
        try {
            $deposit->reverse();
        } catch (InvalidReversal) {
        }

        return $this->wallet->deposit(2);
    });

    expect($next->balance_after)->toBe('102.00')
        ->and($this->wallet->balance)->toBe('102.00')
        ->and($this->ledgerSum($this->wallet->id))->toBe(102_00)
        ->and(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->count())->toBe(1);
});

/*
 * Events and outer transactions.
 */

it('dispatches TransactionRecorded and TransactionReversed after commit', function (): void {
    $deposit = $this->wallet->deposit(10);
    $this->events->exchangeArray([]);
    $baseLevel = DB::transactionLevel();

    $reversal = DB::transaction(function () use ($deposit): WalletTransaction {
        $reversal = $deposit->reverse();

        expect($this->events->getArrayCopy())->toBe([]);

        return $reversal;
    });

    expect(new TransactionReversed($deposit, $reversal))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($this->events->getArrayCopy())->toBe([
            ['recorded', $reversal->id, $baseLevel],
            ['reversed', $deposit->id, $reversal->id, $baseLevel],
        ]);
});

it('passes both entries to TransactionReversed listeners', function (): void {
    $received = null;

    Event::listen(TransactionReversed::class, function (TransactionReversed $event) use (&$received): void {
        $received = $event;
    });

    $deposit = $this->wallet->deposit(10);
    $reversal = $deposit->reverse();

    expect($received?->original)->toBe($deposit)
        ->and($received?->reversal)->toBe($reversal)
        ->and($received?->original->reversal)->toBe($reversal);
});

it('dispatches nothing when the reversal fails, is refused or is replayed', function (): void {
    $deposit = $this->wallet->deposit(10);
    $spent = $this->wallet->deposit(5);
    $deposit->reverse(idempotencyKey: 'k');
    $this->wallet->withdraw(5);
    $this->events->exchangeArray([]);

    expect(fn () => $spent->reverse())->toThrow(InsufficientFunds::class)
        ->and(fn () => $deposit->reverse())->toThrow(InvalidReversal::class)
        ->and($deposit->reverse(idempotencyKey: 'k')->wasRecentlyCreated)->toBeFalse()
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('never dispatches and restores the instance when the outer transaction rolls back', function (): void {
    $deposit = $this->wallet->deposit(10);
    $before = $this->transactionRows();
    $this->events->exchangeArray([]);

    try {
        DB::transaction(function () use ($deposit): void {
            $deposit->reverse();

            expect($this->wallet->balance)->toBe('0.00');

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($this->transactionRows())->toBe($before)
        ->and($this->wallet->balance)->toBe('10.00')
        ->and($this->events->getArrayCopy())->toBe([]);
});

/*
 * Queries.
 */

it('runs a fixed number of queries per reversal', function (): void {
    $deposit = $this->wallet->deposit(10);

    // The check for an existing reversal, the guarded UPDATE, the read of
    // the balance it produced, the INSERT.
    $queries = $this->queriesDuring(fn () => $deposit->reverse());

    expect($queries)->toHaveCount(4)
        ->and($queries[0])->toStartWith('select')
        ->and($queries[0])->toContain('reverses_id')
        ->and($queries[1])->toStartWith('update')
        ->and($queries[2])->toStartWith('select')
        ->and($queries[3])->toStartWith('insert');

    $this->wallet->deposit(10);
    $transfer = $this->wallet->transferTo($this->merchantWallet, 1);

    // The transfer's legs, the counterpart's wallet, the reversal check,
    // two guarded UPDATEs each followed by its read, two INSERTs.
    $queries = $this->queriesDuring(fn () => $transfer->out->reverse());

    expect($queries)->toHaveCount(9)
        ->and($queries[0])->toContain('group_uuid')
        ->and($queries[1])->toContain($this->walletsTable())
        ->and($queries[2])->toContain('reverses_id')
        ->and($queries[3])->toStartWith('update')
        ->and($queries[4])->toStartWith('select')
        ->and($queries[5])->toStartWith('update')
        ->and($queries[6])->toStartWith('select')
        ->and($queries[7])->toStartWith('insert')
        ->and($queries[8])->toStartWith('insert');
});

/*
 * Audit.
 */

it('passes the audit with reversed deposits, withdrawals and transfers', function (): void {
    $this->wallet->deposit(200)->reverse();
    $this->wallet->deposit(100);
    $this->wallet->withdraw(40)->reverse(description: 'Oops');
    $this->wallet->transferTo($this->merchantWallet, 25)->in->reverse();
    $this->merchantWallet->transferTo($this->wallet, 10);
    $this->user->createWallet('kwd', 'KWD')->deposit('1.001')->reverse();

    $report = $this->auditor()->audit();

    expect($report->passed())->toBeTrue()
        ->and($report->issues)->toBe([])
        ->and($report->groupsChecked)->toBe(3);

    expect(Artisan::call('wallet:check'))->toBe(0)
        ->and(Artisan::output())->toContain('and 3 group(s): 0 error(s), 0 warning(s).');
});

it('reports invalid_reversal for a reversal that does not undo its entry', function (string $case): void {
    $deposit = $this->wallet->deposit(100);
    $other = User::create(['name' => 'Other'])->wallet();
    $other->setMinBalance(-1_000_00);

    [$walletId, $row, $problems] = match ($case) {
        'amount' => [$this->wallet->id, ['type' => 'reversal', 'amount' => -50_00, 'balance_after' => 50_00], ['amount']],
        'wallet' => [$other->id, ['type' => 'reversal', 'amount' => -100_00, 'balance_after' => -100_00], ['wallet']],
        'type' => [$this->wallet->id, ['type' => 'withdraw', 'amount' => -100_00, 'balance_after' => 0], ['type']],
        'everything' => [$other->id, ['type' => 'withdraw', 'amount' => 3_00, 'balance_after' => 3_00], ['amount', 'wallet', 'type']],
    };

    $id = $this->insertTransactionRow($walletId, [...$row, 'reverses_id' => $deposit->id]);
    $this->forceBalance($walletId, $row['balance_after']);

    $report = $this->auditor()->audit();

    expect(array_map(fn ($issue) => $issue->code, $report->issues))->toBe([IssueCode::InvalidReversal])
        ->and($report->passed())->toBeFalse();

    $issue = $report->issues[0];

    expect($issue->walletId)->toBe($walletId)
        ->and($issue->transactionId)->toBe($id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain("reverses row {$deposit->id}")
        ->and($issue->context)->toBe([
            'reverses_id' => $deposit->id,
            'problems' => $problems,
            'amount' => $row['amount'],
            'original_amount' => 100_00,
            'original_wallet_id' => $this->wallet->id,
            'type' => $row['type'],
        ]);

    expect(Artisan::call('wallet:check'))->toBe(1)
        ->and(Artisan::output())->toContain('[invalid_reversal]');
})->with(['amount', 'wallet', 'type', 'everything']);

it('reports invalid_reversal for an entry whose amount has no opposite', function (): void {
    DB::table($this->walletsTable())->where('id', $this->wallet->id)->update(['min_balance' => PHP_INT_MIN]);
    $original = $this->insertTransactionRow($this->wallet->id, ['type' => 'withdraw', 'amount' => PHP_INT_MIN, 'balance_after' => PHP_INT_MIN]);
    $this->insertTransactionRow($this->wallet->id, ['type' => 'reversal', 'amount' => 1, 'balance_after' => PHP_INT_MIN + 1, 'reverses_id' => $original]);
    $this->forceBalance($this->wallet->id, PHP_INT_MIN + 1);

    $issues = collect($this->auditor()->audit()->issues)->filter(fn ($issue): bool => $issue->code === IssueCode::InvalidReversal);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()->context['problems'])->toBe(['amount']);

    expect(fn () => WalletTransaction::query()->findOrFail($original)->reverse())->toThrow(InvalidReversal::class, 'cannot be reversed');
});

it('reports reversal_without_link for a reversal that points at nothing', function (): void {
    $this->wallet->deposit(100);
    $id = $this->insertTransactionRow($this->wallet->id, ['type' => 'reversal', 'amount' => -10_00, 'balance_after' => 90_00]);
    $this->forceBalance($this->wallet->id, 90_00);

    $report = $this->auditor()->audit();

    expect(array_map(fn ($issue) => $issue->code, $report->issues))->toBe([IssueCode::ReversalWithoutLink]);

    $issue = $report->issues[0];

    expect($issue->walletId)->toBe($this->wallet->id)
        ->and($issue->transactionId)->toBe($id)
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('-10.00 EGP')
        ->and($issue->context)->toBe(['amount' => -10_00]);
});

it('reports unbalanced_group for a reversal group that does not sum to zero', function (): void {
    $this->wallet->deposit(100);
    $reversal = $this->wallet->transferTo($this->merchantWallet, 30)->out->reverse();
    $fee = User::create(['name' => 'Fee'])->wallet();
    $fee->setMinBalance(-1_000_00);

    $this->insertTransactionRow($fee->id, ['type' => 'fee', 'amount' => -1_00, 'balance_after' => -1_00, 'group_uuid' => $reversal->group_uuid]);
    $this->forceBalance($fee->id, -1_00);

    $report = $this->auditor()->audit();

    expect(array_map(fn ($issue) => $issue->code, $report->issues))->toBe([IssueCode::UnbalancedGroup])
        ->and($report->issues[0]->groupUuid)->toBe($reversal->group_uuid)
        ->and($report->issues[0]->message)->toStartWith('Reversal group')
        ->and($report->issues[0]->context)->toBe(['total' => -1_00, 'leg_count' => 3]);
});

it('checks reversals with one statement per chunk, never one per row', function (): void {
    for ($i = 0; $i < 4; $i++) {
        User::create(['name' => "Chunk {$i}"])->wallet()->deposit(10)->reverse();
    }

    $queries = $this->queriesDuring(fn () => $this->auditor()->audit(null, 2));

    // 6 wallets (with the two of beforeEach), chunk 2 => 3 chunks.
    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'reverses_id')))->toHaveCount(3);
});
