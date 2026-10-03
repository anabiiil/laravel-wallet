<?php

declare(strict_types=1);

use Anabil\Wallet\Audit\IssueCode;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Events\WalletStatusChanged;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Exceptions\InvalidReversal;
use Anabil\Wallet\Exceptions\InvalidStatusTransition;
use Anabil\Wallet\Exceptions\WalletClosed;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Exceptions\WalletFrozen;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * freeze() / unfreeze() / close() and what each status lets through. The
 * rules live in the guarded UPDATEs (the row decides, never the instance),
 * so refusals are checked against the database as well as the exception.
 * A real listener records the events instead of Event::fake(), which would
 * hide the after-commit behaviour.
 */

beforeEach(function (): void {
    $this->wallet = User::create(['name' => 'Ada'])->wallet();
    $this->other = User::create(['name' => 'Bob'])->wallet();
    $this->admin = Admin::create(['name' => 'Root']);
    $this->events = new ArrayObject;

    Event::listen(WalletStatusChanged::class, function (WalletStatusChanged $event): void {
        $this->events[] = $event;
    });
});

// --- Frozen ---------------------------------------------------------------

it('refuses every debit on a frozen wallet and writes nothing', function (Closure $debit): void {
    $deposit = $this->wallet->deposit(100);
    $this->other->deposit(50);
    $transfer = $this->other->transferTo($this->wallet, 20);
    $this->wallet->freeze();

    $e = $this->assertRefusedWithoutWrites(
        fn () => $debit($this, $deposit, $transfer),
        WalletFrozen::class,
        'is frozen',
        $this->wallet,
        $this->other,
    );

    expect($e->wallet->id)->toBe($this->wallet->id)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(120_00)
        ->and($this->wallet->balance)->toBe('120.00');
})->with([
    'withdraw' => fn ($test) => $test->wallet->withdraw(1),
    'transfer out' => fn ($test) => $test->wallet->transferTo($test->other, 1),
    'reversal of a deposit' => fn ($test, WalletTransaction $deposit) => $deposit->reverse(),
    'reversal of an incoming transfer (via the out leg)' => fn ($test, $deposit, $transfer) => $transfer->out->reverse(),
    'reversal of an incoming transfer (via the in leg)' => fn ($test, $deposit, $transfer) => $transfer->in->reverse(),
]);

it('names the amount and the way out in the frozen refusal', function (): void {
    $this->wallet->deposit(100);
    $this->wallet->freeze();

    expect(fn () => $this->wallet->withdraw('12.50'))->toThrow(
        WalletFrozen::class,
        'Cannot debit 12.50 EGP from wallet ['.$this->wallet->id.']: the wallet is frozen and only accepts credits. Unfreeze it with $wallet->unfreeze() first',
    );
});

it('reports a frozen wallet before insufficient funds', function (): void {
    $this->wallet->freeze();

    expect(fn () => $this->wallet->withdraw(1))->toThrow(WalletFrozen::class);
});

it('still credits a frozen wallet', function (): void {
    $this->other->deposit(100);
    $withdrawal = (function (): WalletTransaction {
        $this->wallet->deposit(10);

        return $this->wallet->withdraw(4);
    })();
    $this->wallet->freeze();

    $this->wallet->deposit(5);
    $this->other->transferTo($this->wallet, 7);
    $reversal = $withdrawal->reverse();

    expect($reversal->amount)->toBe('4.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(22_00)
        ->and($this->wallet->balance)->toBe('22.00')
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('frozen')
        ->and($this->ledgerSum($this->wallet->id))->toBe(22_00);
});

it('lets a forced reversal debit a frozen wallet (a chargeback)', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->withdraw(30);
    $this->wallet->freeze();

    $reversal = $deposit->reverse(description: 'Chargeback', force: true);

    expect($reversal->amount)->toBe('-100.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(-30_00)
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('frozen');
});

it('lets a forced reversal of a transfer debit a frozen recipient', function (): void {
    $this->other->deposit(50);
    $transfer = $this->other->transferTo($this->wallet, 20);
    $this->wallet->freeze();

    $transfer->in->reverse(force: true);

    expect((int) $this->walletRow($this->wallet->id)['balance'])->toBe(0)
        ->and((int) $this->walletRow($this->other->id)['balance'])->toBe(50_00);
});

// --- Closed ---------------------------------------------------------------

it('refuses every movement on a closed wallet, forced reversals included, and writes nothing', function (Closure $move, string $message): void {
    $this->other->deposit(100);
    $deposit = $this->wallet->deposit(30);
    $withdrawal = $this->wallet->withdraw(30);
    $this->other->transferTo($this->wallet, 10);
    $transfer = $this->wallet->transferTo($this->other, 10);
    $this->wallet->close();

    $e = $this->assertRefusedWithoutWrites(
        fn () => $move($this, $deposit, $withdrawal, $transfer),
        WalletClosed::class,
        $message,
        $this->wallet,
        $this->other,
    );

    expect($e->wallet->id)->toBe($this->wallet->id);
})->with([
    'deposit' => [fn ($test) => $test->wallet->deposit(1), 'Cannot credit 1.00 EGP to wallet'],
    'withdraw' => [fn ($test) => $test->wallet->withdraw(1), 'Cannot debit 1.00 EGP from wallet'],
    'transfer out' => [fn ($test) => $test->wallet->transferTo($test->other, 1), 'is closed'],
    'transfer in' => [fn ($test) => $test->other->transferTo($test->wallet, 1), 'is closed'],
    'reversal of a deposit' => [fn ($test, $deposit) => $deposit->reverse(), 'is closed'],
    'forced reversal of a deposit' => [fn ($test, $deposit) => $deposit->reverse(force: true), 'is closed'],
    'reversal of a withdrawal (a credit)' => [fn ($test, $deposit, $withdrawal) => $withdrawal->reverse(), 'is closed'],
    'reversal of an outgoing transfer' => [fn ($test, $deposit, $withdrawal, $transfer) => $transfer->in->reverse(force: true), 'is closed'],
]);

it('explains that closing is permanent', function (): void {
    $this->wallet->close();

    expect(fn () => $this->wallet->deposit(5))->toThrow(
        WalletClosed::class,
        'Cannot credit 5.00 EGP to wallet ['.$this->wallet->id.']: the wallet is closed and accepts no movements. Closing is permanent: use another wallet.',
    );
});

it('refuses movements on a wallet whose stored status is invalid', function (): void {
    $this->forceStatus($this->wallet->id, 'bogus');

    $this->assertRefusedWithoutWrites(fn () => $this->wallet->deposit(1), WalletException::class, 'invalid status [bogus]');
});

// --- Reversal races -------------------------------------------------------

it('does not turn a frozen refusal into "already reversed" when no reversal exists', function (): void {
    $deposit = $this->wallet->deposit(100);

    // The wallet is frozen between the "already reversed?" check and the
    // write, as a concurrent request would.
    $this->afterReversalCheck(fn () => $this->forceStatus($this->wallet->id, 'frozen'));

    expect(fn () => $deposit->reverse())->toThrow(WalletFrozen::class);

    expect(DB::table($this->transactionsTable())->where('reverses_id', $deposit->id)->exists())->toBeFalse();
});

it('does not turn a closed refusal into "already reversed" when no reversal exists', function (): void {
    $deposit = $this->wallet->deposit(100);

    $this->afterReversalCheck(fn () => $this->forceStatus($this->wallet->id, 'closed'));

    expect(fn () => $deposit->reverse(force: true))->toThrow(WalletClosed::class);
});

it('reports "already reversed" when a status refusal follows a concurrent reversal', function (string $status): void {
    $deposit = $this->wallet->deposit(100);

    $this->afterReversalCheck(function () use ($deposit, $status): void {
        $this->competingReversal($deposit);
        $this->forceStatus($this->wallet->id, $status);
    });

    try {
        $deposit->reverse(force: true);
        $this->fail('The reversal went through.');
    } catch (InvalidReversal $e) {
        expect($e->reversal)->not->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(WalletException::class);
    }
})->with(['closed' => 'closed']);

it('reports "already reversed" when a frozen refusal follows a concurrent reversal', function (): void {
    $deposit = $this->wallet->deposit(100);
    $this->wallet->deposit(50);

    $this->afterReversalCheck(function () use ($deposit): void {
        $this->competingReversal($deposit);
        $this->forceStatus($this->wallet->id, 'frozen');
    });

    try {
        $deposit->reverse();
        $this->fail('The reversal went through.');
    } catch (InvalidReversal $e) {
        expect($e->reversal)->not->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(WalletFrozen::class);
    }
});

// --- Transitions ----------------------------------------------------------

it('freezes an active wallet', function (): void {
    Carbon::setTestNow('2026-03-01 10:00:00');

    try {
        $result = $this->wallet->freeze('Suspicious activity', $this->admin);
    } finally {
        Carbon::setTestNow();
    }

    $row = $this->walletRow($this->wallet->id);

    expect($result)->toBe($this->wallet)
        ->and($row['status'])->toBe('frozen')
        ->and((string) $row['updated_at'])->toStartWith('2026-03-01 10:00:00')
        ->and($this->wallet->status)->toBe(WalletStatus::Frozen)
        ->and($this->wallet->isFrozen())->toBeTrue()
        ->and($this->wallet->isActive())->toBeFalse()
        ->and($this->wallet->isClosed())->toBeFalse()
        ->and($this->wallet->updated_at?->toDateTimeString())->toBe('2026-03-01 10:00:00')
        ->and($this->wallet->isDirty())->toBeFalse()
        ->and($this->events->getArrayCopy())->toHaveCount(1);

    $event = $this->events[0];

    expect($event->wallet)->toBe($this->wallet)
        ->and($event->from)->toBe(WalletStatus::Active)
        ->and($event->to)->toBe(WalletStatus::Frozen)
        ->and($event->reason)->toBe('Suspicious activity')
        ->and($event->performedBy)->toBe($this->admin);
});

it('unfreezes a frozen wallet', function (): void {
    $this->wallet->freeze();
    $this->wallet->unfreeze('Cleared', $this->admin);

    expect($this->walletRow($this->wallet->id)['status'])->toBe('active')
        ->and($this->wallet->isActive())->toBeTrue()
        ->and($this->events->getArrayCopy())->toHaveCount(2)
        ->and($this->events[1]->from)->toBe(WalletStatus::Frozen)
        ->and($this->events[1]->to)->toBe(WalletStatus::Active)
        ->and($this->events[1]->reason)->toBe('Cleared');

    $this->wallet->deposit(10);
    $this->wallet->withdraw(4);

    expect((int) $this->walletRow($this->wallet->id)['balance'])->toBe(6_00);
});

it('closes an empty wallet, active or frozen', function (bool $frozen): void {
    $this->wallet->deposit(10);
    $this->wallet->withdraw(10);

    if ($frozen) {
        $this->wallet->freeze();
    }

    $this->wallet->close('Account closed', $this->admin);

    $event = $this->events[count($this->events) - 1];

    expect($this->walletRow($this->wallet->id)['status'])->toBe('closed')
        ->and($this->wallet->isClosed())->toBeTrue()
        ->and($event->from)->toBe($frozen ? WalletStatus::Frozen : WalletStatus::Active)
        ->and($event->to)->toBe(WalletStatus::Closed)
        ->and($event->performedBy)->toBe($this->admin);
})->with(['active' => false, 'frozen' => true]);

it('reports the status actually left even when the instance is stale', function (): void {
    $stale = Wallet::findOrFail($this->wallet->id);
    $this->wallet->freeze();

    expect($stale->isActive())->toBeTrue();

    $stale->close();

    expect($this->events[1]->from)->toBe(WalletStatus::Frozen)
        ->and($stale->isClosed())->toBeTrue();
});

it('treats a repeated transition as a no-op without an event', function (Closure $first, Closure $again, string $status): void {
    $first($this->wallet);
    $count = count($this->events);
    $row = $this->walletRow($this->wallet->id);

    expect($again($this->wallet))->toBe($this->wallet)
        ->and($this->walletRow($this->wallet->id))->toBe($row)
        ->and($this->walletRow($this->wallet->id)['status'])->toBe($status)
        ->and($this->events->getArrayCopy())->toHaveCount($count);
})->with([
    'freeze a frozen wallet' => [fn (Wallet $w) => $w->freeze(), fn (Wallet $w) => $w->freeze(), 'frozen'],
    'unfreeze an active wallet' => [fn (Wallet $w) => null, fn (Wallet $w) => $w->unfreeze(), 'active'],
    'close a closed wallet' => [fn (Wallet $w) => $w->close(), fn (Wallet $w) => $w->close(), 'closed'],
]);

it('syncs a stale instance on a no-op', function (): void {
    Wallet::findOrFail($this->wallet->id)->freeze();

    expect($this->wallet->isActive())->toBeTrue();

    $this->wallet->freeze();

    expect($this->wallet->isFrozen())->toBeTrue()
        ->and($this->wallet->isDirty())->toBeFalse()
        ->and($this->events->getArrayCopy())->toHaveCount(1);
});

it('refuses to leave the closed status', function (Closure $transition, string $to): void {
    $this->wallet->close();
    $count = count($this->events);

    $e = $this->assertRefusedWithoutWrites(
        fn () => $transition($this->wallet),
        InvalidStatusTransition::class,
        'Cannot change wallet ['.$this->wallet->id.'] to ['.$to.']: the wallet is closed, and closing is permanent.',
        $this->wallet,
    );

    expect($e->wallet)->toBe($this->wallet)
        ->and($e->to->value)->toBe($to)
        ->and($this->events->getArrayCopy())->toHaveCount($count);
})->with([
    'freeze' => [fn (Wallet $w) => $w->freeze(), 'frozen'],
    'unfreeze' => [fn (Wallet $w) => $w->unfreeze(), 'active'],
]);

it('refuses to close a wallet that holds money or owes it', function (Closure $setUp, string $balance, bool $frozen): void {
    $setUp($this->wallet);

    if ($frozen) {
        $this->wallet->freeze();
    }

    $count = count($this->events);

    $this->assertRefusedWithoutWrites(
        fn () => $this->wallet->close(),
        InvalidStatusTransition::class,
        'Cannot close wallet ['.$this->wallet->id.']: its balance is '.$balance.' EGP and only an empty wallet can be closed.',
        $this->wallet,
    );

    expect($this->walletRow($this->wallet->id)['status'])->toBe($frozen ? 'frozen' : 'active')
        ->and($this->events->getArrayCopy())->toHaveCount($count);
})->with([
    'positive' => [fn (Wallet $w) => $w->deposit('10.50'), '10.50'],
    'negative (a credit line in use)' => [function (Wallet $w): void {
        $w->setMinBalance(-50);
        $w->withdraw(20);
    }, '-20.00'],
])->with(['active' => false, 'frozen' => true]);

it('checks the balance in the database, not on the instance', function (): void {
    $this->wallet->deposit(10);
    $stale = Wallet::findOrFail($this->wallet->id);
    $this->wallet->withdraw(10);

    // The stale instance still says 10.00; the row says 0.
    $stale->close();

    expect($this->walletRow($this->wallet->id)['status'])->toBe('closed');

    $empty = Wallet::findOrFail($this->other->id);
    $this->other->deposit(1);

    expect(fn () => $empty->close())->toThrow(InvalidStatusTransition::class, 'its balance is 1.00 EGP');
});

it('validates the reason and the actor before writing', function (Closure $transition, string $message): void {
    $this->assertRefusedWithoutWrites(fn () => $transition($this), InvalidStatusTransition::class, $message, $this->wallet);

    expect($this->events->getArrayCopy())->toBe([]);
})->with([
    'reason too long' => [fn ($test) => $test->wallet->freeze(str_repeat('x', 256)), 'is 256 characters long: at most 255 are allowed'],
    'multibyte reason too long' => [fn ($test) => $test->wallet->close(str_repeat('é', 256)), 'is 256 characters long'],
    'unsaved performer' => [fn ($test) => $test->wallet->freeze(performedBy: new Admin(['name' => 'Ghost'])), 'The performedBy model ['.Admin::class.'] must be saved'],
]);

it('accepts a reason of exactly 255 characters and drops a blank one', function (): void {
    $this->wallet->freeze(str_repeat('é', 255));
    $this->wallet->unfreeze('   ');

    expect($this->events[0]->reason)->toBe(str_repeat('é', 255))
        ->and($this->events[1]->reason)->toBeNull()
        ->and($this->events[1]->performedBy)->toBeNull();
});

it('refuses to change the status of an unsaved wallet', function (): void {
    expect(fn () => (new Wallet)->freeze())->toThrow(WalletException::class, 'unsaved wallet');
});

it('refuses to change the status of a wallet that no longer exists', function (): void {
    DB::table($this->walletsTable())->where('id', $this->wallet->id)->delete();

    expect(fn () => $this->wallet->freeze())->toThrow(WalletException::class, 'no longer exists');
});

it('refuses a transition from an invalid stored status', function (): void {
    $this->forceStatus($this->wallet->id, 'bogus');

    expect(fn () => $this->wallet->freeze())->toThrow(InvalidStatusTransition::class, 'stored status [bogus] is not a valid status');
});

// --- Stale instances ------------------------------------------------------

it('trusts the database over a stale instance', function (): void {
    $this->wallet->deposit(100);
    $stale = Wallet::findOrFail($this->wallet->id);

    Wallet::findOrFail($this->wallet->id)->freeze();

    expect($stale->isActive())->toBeTrue();

    $this->assertRefusedWithoutWrites(fn () => $stale->withdraw(1), WalletFrozen::class, null, $stale);

    $stale->deposit(1);

    expect($stale->balance)->toBe('101.00');
});

it('leaves the instance clean after a transition, so later saves work', function (): void {
    $this->wallet->freeze();
    $this->wallet->update(['meta' => ['note' => 'frozen by ops']]);
    $this->wallet->unfreeze();
    $this->wallet->setMinBalance(-10);

    $row = $this->walletRow($this->wallet->id);

    expect(json_decode((string) $row['meta'], true))->toBe(['note' => 'frozen by ops'])
        ->and($row['status'])->toBe('active')
        ->and((int) $row['min_balance'])->toBe(-10_00)
        ->and($this->wallet->isDirty())->toBeFalse();
});

// --- Idempotent replays ---------------------------------------------------

it('replays earlier keyed operations on a frozen or closed wallet', function (Closure $status): void {
    $deposit = $this->wallet->deposit(100, idempotencyKey: 'd');
    $withdrawal = $this->wallet->withdraw(40, idempotencyKey: 'w');
    $transfer = $this->wallet->transferTo($this->other, 60, idempotencyKey: 't');
    $refunded = $this->wallet->deposit(5);
    $reversal = $refunded->reverse(idempotencyKey: 'r');

    $status($this->wallet);
    $rows = $this->transactionRows();

    expect($this->wallet->deposit(100, idempotencyKey: 'd')->id)->toBe($deposit->id)
        ->and($this->wallet->withdraw(40, idempotencyKey: 'w')->id)->toBe($withdrawal->id)
        ->and($this->wallet->transferTo($this->other, 60, idempotencyKey: 't'))
        ->replayed()->toBeTrue()
        ->out->id->toBe($transfer->out->id)
        ->and(WalletTransaction::query()->findOrFail($refunded->id)->reverse(idempotencyKey: 'r')->id)->toBe($reversal->id)
        ->and($this->transactionRows())->toBe($rows);

    // A new operation is still refused.
    expect(fn () => $this->wallet->withdraw(1, idempotencyKey: 'new'))->toThrow(WalletException::class);
})->with([
    'frozen' => fn (Wallet $w) => $w->freeze(),
    'closed' => fn (Wallet $w) => $w->close(),
]);

// --- Lockdown -------------------------------------------------------------

it('refuses to change the status through save()', function (mixed $status): void {
    $this->wallet->forceFill(['status' => $status]);

    $this->assertRefusedWithoutWrites(fn () => $this->wallet->save(), ImmutableWallet::class, 'freeze()');
})->with(['string' => 'frozen', 'enum' => WalletStatus::Closed]);

it('refuses to change the status through a query', function (Closure $write): void {
    $this->assertRefusedWithoutWrites($write, ImmutableWallet::class, 'Cannot update [status] on wallets through a query');
})->with([
    'update' => fn () => Wallet::query()->whereKey(test()->wallet->id)->update(['status' => 'frozen']),
    'updateFrom' => fn () => Wallet::query()->updateFrom(['status' => 'closed']),
]);

it('refuses to create a wallet that is not active', function (string $status, bool $listener): void {
    $count = DB::table($this->walletsTable())->count();
    $wallet = new Wallet;
    $wallet->forceFill(['holder_type' => 'user', 'holder_id' => 1, 'name' => 'born-'.$status, 'currency' => 'EGP']);

    if ($listener) {
        Wallet::creating(fn (Wallet $w) => $w->forceFill(['status' => $status]));
    } else {
        $wallet->forceFill(['status' => $status]);
    }

    expect(fn () => $wallet->save())->toThrow(ImmutableWallet::class, 'A wallet must be created active');

    expect(DB::table($this->walletsTable())->count())->toBe($count);
})->with(['frozen', 'closed'])->with(['set before save' => false, 'set by a creating listener' => true]);

it('creates wallets active, explicitly or by default', function (): void {
    $wallet = new Wallet;
    $wallet->forceFill(['holder_type' => 'user', 'holder_id' => 1, 'name' => 'explicit', 'currency' => 'EGP', 'status' => WalletStatus::Active]);
    $wallet->save();

    expect($this->walletRow($wallet->id)['status'])->toBe('active')
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('active');
});

it('still allows bulk updates of meta', function (): void {
    $this->wallet->freeze();

    Wallet::query()->whereKey($this->wallet->id)->update(['meta' => json_encode(['a' => 1])]);

    expect(json_decode((string) $this->walletRow($this->wallet->id)['meta'], true))->toBe(['a' => 1])
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('frozen');
});

it('filters wallets by status', function (): void {
    $closed = User::create(['name' => 'Cy'])->wallet();
    $this->other->freeze();
    $closed->close();

    expect(Wallet::query()->active()->pluck('id')->all())->toBe([$this->wallet->id])
        ->and(Wallet::query()->frozen()->pluck('id')->all())->toBe([$this->other->id])
        ->and(Wallet::query()->closed()->pluck('id')->all())->toBe([$closed->id])
        ->and(User::query()->findOrFail($this->other->holder_id)->wallets()->frozen()->count())->toBe(1);
});

// --- Events and transactions ----------------------------------------------

it('dispatches WalletStatusChanged after commit', function (): void {
    expect(new WalletStatusChanged($this->wallet, WalletStatus::Active, WalletStatus::Frozen))
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class);

    DB::transaction(function (): void {
        $this->wallet->freeze();
        $this->wallet->unfreeze();

        expect($this->events->getArrayCopy())->toBe([]);
    });

    expect($this->events->getArrayCopy())->toHaveCount(2);
});

it('dispatches nothing when a transition fails', function (): void {
    $this->wallet->deposit(1);

    expect(fn () => $this->wallet->close())->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $this->wallet->freeze(str_repeat('x', 300)))->toThrow(InvalidStatusTransition::class)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('restores the instance and dispatches nothing when the outer transaction rolls back', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $this->wallet->update(['meta' => ['a' => 1]]);
    Carbon::setTestNow('2026-02-01 00:00:00');

    try {
        DB::transaction(function (): void {
            $this->wallet->freeze();

            expect($this->wallet->isFrozen())->toBeTrue();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    } finally {
        Carbon::setTestNow();
    }

    expect($this->events->getArrayCopy())->toBe([])
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('active')
        ->and($this->wallet->status)->toBe(WalletStatus::Active)
        ->and($this->wallet->updated_at?->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($this->wallet->isDirty())->toBeFalse();

    $this->wallet->update(['meta' => ['b' => 2]]);

    expect($this->walletRow($this->wallet->id)['status'])->toBe('active');
});

it('unwinds several transitions rolled back together, whatever the nesting', function (): void {
    try {
        DB::transaction(function (): void {
            $this->wallet->freeze();

            try {
                DB::transaction(function (): void {
                    $this->wallet->unfreeze();
                    $this->wallet->freeze();
                    $this->wallet->unfreeze();

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }

            expect($this->wallet->status)->toBe(WalletStatus::Frozen)
                ->and($this->walletRow($this->wallet->id)['status'])->toBe('frozen');

            $this->wallet->unfreeze();
            $this->wallet->freeze();

            throw new RuntimeException('outer');
        });
    } catch (RuntimeException) {
    }

    expect($this->wallet->status)->toBe(WalletStatus::Active)
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('active')
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('restores the status from before the earliest of several rolled back transitions', function (): void {
    try {
        DB::transaction(function (): void {
            $this->wallet->freeze();
            $this->wallet->unfreeze();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    // Laravel runs the rollback callbacks in registration order: undoing
    // each change blindly would leave the "from" of the last one (frozen).
    expect($this->wallet->status)->toBe(WalletStatus::Active)
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('active');
});

it('keeps the new status when the outer transaction commits', function (): void {
    DB::transaction(fn () => $this->wallet->freeze());

    expect($this->wallet->isFrozen())->toBeTrue()
        ->and($this->walletRow($this->wallet->id)['status'])->toBe('frozen');
});

// --- Audit ----------------------------------------------------------------

it('passes the audit with active, frozen and closed wallets', function (): void {
    $closed = User::create(['name' => 'Cy'])->wallet();
    $this->wallet->deposit(100);
    $this->wallet->transferTo($this->other, 30);
    $this->other->freeze();
    $this->other->deposit(5);
    $closed->deposit(10);
    $closed->withdraw(10);
    $closed->close();

    expect($this->auditor()->audit()->passed())->toBeTrue();
});

it('reports closed_with_balance and invalid_status from the per-chunk wallet statement', function (): void {
    $closed = User::create(['name' => 'Cy'])->wallet();
    $closed->deposit(10);
    $this->forceStatus($closed->id, 'closed');
    $this->forceStatus($this->other->id, 'bogus');
    $this->wallet->deposit(1);

    $report = null;
    $queries = $this->queriesDuring(function () use (&$report): void {
        $report = $this->auditor()->audit(null, 2);
    });

    $byCode = collect($report->issues)->keyBy(fn ($issue): string => $issue->code->value);

    expect($report->passed())->toBeFalse()
        ->and($byCode->keys()->sort()->values()->all())->toBe(['closed_with_balance', 'invalid_status'])
        ->and($byCode['closed_with_balance']->walletId)->toBe($closed->id)
        ->and($byCode['closed_with_balance']->code)->toBe(IssueCode::ClosedWithBalance)
        ->and($byCode['closed_with_balance']->message)->toContain('10.00 EGP')
        ->and($byCode['closed_with_balance']->context)->toBe(['balance' => 10_00])
        ->and($byCode['invalid_status']->walletId)->toBe($this->other->id)
        ->and($byCode['invalid_status']->message)->toContain('[bogus]')
        ->and($byCode['invalid_status']->severity()->value)->toBe('error');

    // 3 wallets, chunk 2 => 2 chunks => 2 wallet statements, nothing per wallet.
    $walletQueries = array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'coalesce(agg.sum_amount'));
    $statusReads = array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'status') && ! str_contains(strtolower($sql), 'coalesce(agg.sum_amount'));

    expect($walletQueries)->toHaveCount(2)
        ->and($statusReads)->toBe([]);
});
