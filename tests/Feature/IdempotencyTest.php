<?php

declare(strict_types=1);

use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\Fingerprint;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\OrderPayment;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * A real listener is used instead of Event::fake(), so the after-commit
 * dispatch behaves as in production.
 */

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
    $this->wallet = $this->user->wallet();
    $this->recorded = new ArrayObject;

    Event::listen(TransactionRecorded::class, function (TransactionRecorded $event): void {
        $this->recorded[] = $event->transaction->id;
    });
});

it('replays a deposit made with the same key', function (): void {
    $first = $this->wallet->deposit(100, idempotencyKey: 'order-1');
    $again = $this->wallet->deposit(100, idempotencyKey: 'order-1');

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($again)->toBeInstanceOf(WalletTransaction::class)
        ->and($again)->not->toBe($first)
        ->and($again->id)->toBe($first->id)
        ->and($again->exists)->toBeTrue()
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->amount)->toBe('100.00')
        ->and($again->idempotency_key)->toBe('order-1')
        ->and($again->wallet)->toBe($this->wallet)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(100_00)
        ->and($this->wallet->balance)->toBe('100.00')
        ->and($this->transactionRows())->toHaveCount(1)
        ->and($this->recorded->getArrayCopy())->toBe([$first->id]);
});

it('returns the replayed entry with the given wallet and relations, without touching the instance', function (): void {
    $order = Order::create();
    $admin = Admin::create(['name' => 'Root']);
    $first = $this->wallet->deposit(1, reference: $order, performedBy: $admin, idempotencyKey: 'k');

    $other = Wallet::findOrFail($this->wallet->id);
    $this->wallet->deposit('0.50');

    $again = null;

    $queries = $this->queriesDuring(function () use ($other, $order, $admin, &$again): void {
        $again = $other->deposit(1, reference: $order, performedBy: $admin, idempotencyKey: 'k');
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toStartWith('select')
        ->and($again?->id)->toBe($first->id)
        ->and($again?->wallet)->toBe($other)
        ->and($again?->reference)->toBe($order)
        ->and($again?->performedBy)->toBe($admin)
        ->and($again?->amount)->toBe('1.00')
        // The instance keeps its stale balance; refresh() brings it up to date.
        ->and($other->balance)->toBe('1.00')
        ->and($other->isDirty())->toBeFalse()
        ->and($other->refresh()->balance)->toBe('1.50');
});

it('replays a withdrawal even when the wallet can no longer cover it', function (): void {
    $this->wallet->deposit(100);
    $first = $this->wallet->withdraw(100, idempotencyKey: 'payout-1');

    expect((int) $this->walletRow($this->wallet->id)['balance'])->toBe(0);

    $again = $this->wallet->withdraw(100, idempotencyKey: 'payout-1');

    $this->wallet->setMinBalance(50);

    $third = $this->wallet->withdraw(100, idempotencyKey: 'payout-1');

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($third->id)->toBe($first->id)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(0)
        ->and($this->transactionRows())->toHaveCount(2)
        ->and($this->recorded->getArrayCopy())->toHaveCount(2);
});

it('refuses to reuse a key for a different operation', function (string $change): void {
    // Forced to share the holder's key (ids are not reset between tests on
    // MySQL/Postgres, so they would not coincide otherwise): the
    // "reference morph type" dataset below swaps the reference for the
    // holder, and must prove the fingerprint differs because of the
    // reference's morph *type*, not merely because of a different id.
    $order = Order::forceCreate(['id' => $this->user->getKey()]);
    $admin = Admin::create(['name' => 'Root']);

    $this->wallet->deposit(10, 'topup', reference: $order, description: 'Top up', meta: ['a' => 1], performedBy: $admin, idempotencyKey: 'k');

    expect($this->user->getKey())->toBe($order->getKey());

    $snapshot = $this->transactionRows();

    $args = [
        'amount' => 10,
        'type' => 'topup',
        'reference' => $order,
        'description' => 'Top up',
        'meta' => ['a' => 1],
        'performedBy' => $admin,
        'idempotencyKey' => 'k',
    ];

    $method = 'deposit';

    match ($change) {
        'amount' => $args['amount'] = '10.01',
        'direction' => $method = 'withdraw',
        'type' => $args['type'] = 'bonus',
        // A forced id: on PostgreSQL forceCreate() above did not advance the
        // orders sequence, so a plain create() could reuse the same id.
        'reference model' => $args['reference'] = Order::forceCreate(['id' => $order->getKey() + 1000]),
        'reference morph type' => $args['reference'] = $this->user,
        'no reference' => $args['reference'] = null,
        'performedBy' => $args['performedBy'] = Admin::create(['name' => 'Other']),
        'no performedBy' => $args['performedBy'] = null,
        'description' => $args['description'] = 'Top-up',
        'meta value' => $args['meta'] = ['a' => 2],
        'meta value type' => $args['meta'] = ['a' => '1'],
        'no meta' => $args['meta'] = [],
    };

    try {
        $this->wallet->{$method}(...$args);
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->key)->toBe('k')
            ->and($e->walletId)->toBe($this->wallet->id)
            ->and($e->transactionId)->toBe((int) $snapshot[0]['id'])
            ->and($e->getMessage())->toContain('[k]')
            ->and($e->getMessage())->toContain('different operation');
    }

    expect($this->transactionRows())->toBe($snapshot)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(10_00)
        ->and($this->wallet->balance)->toBe('10.00')
        ->and($this->recorded->getArrayCopy())->toHaveCount(1);
})->with([
    'amount', 'direction', 'type', 'reference model', 'reference morph type', 'no reference',
    'performedBy', 'no performedBy', 'description', 'meta value', 'meta value type', 'no meta',
]);

it('ignores the key order of meta but not the order of lists', function (): void {
    $this->wallet->deposit(1, meta: ['a' => 1, 'b' => ['y' => 1, 'x' => [2, 1]]], idempotencyKey: 'k');

    $again = $this->wallet->deposit(1, meta: ['b' => ['x' => [2, 1], 'y' => 1], 'a' => 1], idempotencyKey: 'k');

    expect($again->wasRecentlyCreated)->toBeFalse()
        ->and(fn () => $this->wallet->deposit(1, meta: ['a' => 1, 'b' => ['y' => 1, 'x' => [1, 2]]], idempotencyKey: 'k'))
        ->toThrow(IdempotencyConflict::class)
        ->and($this->transactionRows())->toHaveCount(1);
});

it('fingerprints an int and a numeric-string reference key the same', function (): void {
    $order = Order::create();
    $stringKeyed = Order::findOrFail($order->id);
    $stringKeyed->setAttribute('id', (string) $order->id);

    expect(Fingerprint::for(100, new Details('deposit', $order)))
        ->toBe(Fingerprint::for(100, new Details('deposit', $stringKeyed)));

    $first = $this->wallet->deposit(1, reference: $order, idempotencyKey: 'k');
    $again = $this->wallet->deposit(1, reference: $stringKeyed, idempotencyKey: 'k');

    expect($again->id)->toBe($first->id)
        ->and($this->transactionRows())->toHaveCount(1);
});

it('treats a WalletOperation and the equivalent named arguments as the same operation', function (): void {
    $order = Order::create();

    $first = $this->wallet->deposit(1, new OrderPayment($order, 'purchase'), idempotencyKey: 'k');

    $again = $this->wallet->deposit(
        1,
        'purchase',
        reference: $order,
        description: 'Payment for order #'.$order->id,
        meta: ['order_id' => $order->id],
        idempotencyKey: 'k',
    );

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($this->transactionRows())->toHaveCount(1);
});

it('scopes keys to the wallet', function (): void {
    $savings = $this->user->wallet('savings');
    $other = Merchant::create(['name' => 'Shop'])->wallet();

    $a = $this->wallet->deposit(1, idempotencyKey: 'shared');
    $b = $savings->deposit(1, idempotencyKey: 'shared');
    $c = $other->deposit(1, idempotencyKey: 'shared');

    expect([$a->wasRecentlyCreated, $b->wasRecentlyCreated, $c->wasRecentlyCreated])->toBe([true, true, true])
        ->and(array_unique([$a->id, $b->id, $c->id]))->toHaveCount(3)
        ->and($this->user->balance())->toBe('1.00')
        ->and($this->user->balance('savings'))->toBe('1.00')
        ->and((int) $this->walletRow($other->id)['balance'])->toBe(100)
        ->and($this->transactionRows())->toHaveCount(3);
});

it('writes every time without a key', function (): void {
    $this->wallet->deposit(1);
    $this->wallet->deposit(1);

    expect($this->transactionRows())->toHaveCount(2)
        ->and(array_column($this->transactionRows(), 'idempotency_key'))->toBe([null, null])
        ->and(array_column($this->transactionRows(), 'idempotency_hash'))->toBe([null, null])
        ->and($this->wallet->balance)->toBe('2.00');
});

it('rejects invalid keys without writing', function (string $key): void {
    expect(fn () => $this->wallet->deposit(1, idempotencyKey: $key))->toThrow(InvalidTransaction::class)
        ->and(fn () => $this->wallet->withdraw(1, idempotencyKey: $key))->toThrow(InvalidTransaction::class)
        ->and($this->transactionRows())->toBe([]);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'tabs and newlines' => ["\t\n"],
    '129 characters' => [str_repeat('k', 129)],
    '129 multibyte characters' => [str_repeat('é', 129)],
    'invalid UTF-8' => ["key-\xff"],
]);

it('accepts valid keys and stores them verbatim', function (string $key): void {
    $tx = $this->wallet->deposit(1, idempotencyKey: $key);

    expect($tx->idempotency_key)->toBe($key)
        ->and($this->transactionRows()[0]['idempotency_key'])->toBe($key)
        ->and($this->wallet->deposit(1, idempotencyKey: $key)->id)->toBe($tx->id);
})->with([
    '128 characters' => [str_repeat('k', 128)],
    '128 multibyte characters' => [str_repeat('é', 128)],
    'unicode' => ['ключ-🔑-支付'],
    'surrounding spaces' => [' Order-1 '],
]);

it('reports the key length in the error', function (): void {
    expect(fn () => $this->wallet->deposit(1, idempotencyKey: str_repeat('é', 129)))
        ->toThrow(InvalidTransaction::class, 'is 129 characters long: at most 128');
});

it('does not consume the key when the write fails', function (): void {
    expect(fn () => $this->wallet->withdraw(50, idempotencyKey: 'payout'))->toThrow(InsufficientFunds::class)
        ->and($this->transactionRows())->toBe([]);

    $this->wallet->deposit(100);

    $tx = $this->wallet->withdraw(50, idempotencyKey: 'payout');

    expect($tx->wasRecentlyCreated)->toBeTrue()
        ->and($tx->idempotency_key)->toBe('payout')
        ->and($this->wallet->balance)->toBe('50.00')
        ->and($this->wallet->withdraw(50, idempotencyKey: 'payout')->id)->toBe($tx->id)
        ->and($this->wallet->balance)->toBe('50.00');
});

it('validates the request before looking the key up', function (): void {
    $this->wallet->deposit(1, idempotencyKey: 'k');

    expect(fn () => $this->wallet->deposit(1, 'reversal', idempotencyKey: 'k'))->toThrow(InvalidTransaction::class, 'reserved')
        ->and(fn () => $this->wallet->deposit(1, description: str_repeat('x', 256), idempotencyKey: 'k'))->toThrow(InvalidTransaction::class)
        ->and(fn () => $this->wallet->deposit(1, reference: new Order, idempotencyKey: 'k'))->toThrow(InvalidTransaction::class)
        ->and(fn () => $this->wallet->deposit(0, idempotencyKey: 'k'))->toThrow(InvalidAmount::class)
        ->and(fn () => $this->wallet->deposit('1.001', idempotencyKey: 'k'))->toThrow(InvalidAmount::class)
        ->and($this->transactionRows())->toHaveCount(1);
});

it('costs one extra lookup query with a key', function (): void {
    $queries = $this->queriesDuring(fn () => $this->wallet->deposit(1, idempotencyKey: 'k'));

    expect($queries)->toHaveCount(4)
        ->and($queries[0])->toStartWith('select')
        ->and($queries[0])->toContain('idempotency_key')
        ->and($queries[1])->toStartWith('update')
        ->and($queries[2])->toStartWith('select')
        ->and($queries[3])->toStartWith('insert');
});

it('returns the winner of a race with a matching request', function (): void {
    $winner = null;

    $this->afterIdempotencyLookup(function () use (&$winner): void {
        $winner = $this->competingEntry($this->wallet, 30_00, new Details('deposit'), 'race');
    });

    $tx = $this->wallet->deposit(30, idempotencyKey: 'race');

    expect($winner)->not->toBeNull()
        ->and($tx->id)->toBe($winner)
        ->and($tx->wasRecentlyCreated)->toBeFalse()
        ->and($tx->amount)->toBe('30.00')
        ->and($tx->wallet)->toBe($this->wallet)
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(30_00)
        ->and($this->ledgerSum($this->wallet->id))->toBe(30_00)
        ->and(DB::table($this->transactionsTable())->where('idempotency_key', 'race')->count())->toBe(1)
        ->and($this->wallet->balance)->toBe('0.00')
        ->and($this->recorded->getArrayCopy())->toBe([]);
});

it('throws a conflict and rolls back when the race winner did something else', function (): void {
    $this->wallet->deposit(10);

    $this->afterIdempotencyLookup(function (): void {
        $this->competingEntry($this->wallet, 99, new Details('deposit'), 'race');
    });

    try {
        $this->wallet->deposit(30, idempotencyKey: 'race');
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->key)->toBe('race')
            ->and($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class);
    }

    expect((int) $this->walletRow($this->wallet->id)['balance'])->toBe(10_99)
        ->and($this->ledgerSum($this->wallet->id))->toBe(10_99)
        ->and($this->transactionRows())->toHaveCount(2)
        ->and($this->wallet->balance)->toBe('10.00')
        ->and($this->recorded->getArrayCopy())->toHaveCount(1);
});

it('recovers from a race inside an outer transaction and keeps it usable', function (): void {
    $winner = null;

    $this->afterIdempotencyLookup(function () use (&$winner): void {
        $winner = $this->competingEntry($this->wallet, 5_00, new Details('withdraw'), 'race', Fingerprint::for(5_00, new Details('deposit')));
    });

    [$replayed, $next] = DB::transaction(fn (): array => [
        $this->wallet->deposit(5, idempotencyKey: 'race'),
        $this->wallet->deposit(2),
    ]);

    expect($replayed->id)->toBe($winner)
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and($next->wasRecentlyCreated)->toBeTrue()
        ->and($next->balance_after)->toBe('7.00')
        ->and($this->wallet->balance)->toBe('7.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(7_00)
        ->and($this->ledgerSum($this->wallet->id))->toBe(7_00)
        ->and($this->transactionRows())->toHaveCount(2)
        ->and($this->recorded->getArrayCopy())->toBe([$next->id]);
});

it('rethrows the unique violation when no entry is found for the key afterwards', function (): void {
    $winner = null;

    $this->afterIdempotencyLookup(function () use (&$winner): void {
        $winner = $this->competingEntry($this->wallet, 1, new Details('deposit'), 'race');
    });

    // The violating row is gone by the time of the re-lookup, as if the
    // violation came from something other than a committed winner.
    Event::listen(TransactionRolledBack::class, function () use (&$winner): void {
        DB::table($this->transactionsTable())->where('id', $winner)->delete();
    });

    expect(fn () => $this->wallet->deposit('0.05', idempotencyKey: 'race'))->toThrow(UniqueConstraintViolationException::class)
        ->and($this->transactionRows())->toBe([])
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(1)
        ->and($this->wallet->balance)->toBe('0.00')
        ->and($this->recorded->getArrayCopy())->toBe([]);
});

it('never lets NULL keys collide', function (): void {
    $this->insertTransactionRow($this->wallet->id, ['idempotency_key' => null]);

    expect($this->wallet->deposit(1)->wasRecentlyCreated)->toBeTrue()
        ->and($this->transactionRows())->toHaveCount(2);
});

it('stores a stable, lowercase hex fingerprint', function (): void {
    $order = Order::create();

    $tx = $this->wallet->deposit(15, 'topup', reference: $order, description: 'Café', meta: ['b' => 1.0, 'a' => [3, 1]], idempotencyKey: 'k');

    $hash = $this->transactionRows()[0]['idempotency_hash'];

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->toBe($tx->idempotency_hash)
        ->and($hash)->toBe(Fingerprint::for(15_00, new Details('topup', $order, 'Café', ['a' => [3, 1], 'b' => 1.0])));
});

it('keeps the fingerprint payload stable', function (): void {
    // Hard-coded on purpose: if this changes, stored keys from earlier
    // releases would conflict on retry. Bump Fingerprint::VERSION instead of
    // updating the expected value silently.
    $reference = (new Order)->forceFill(['id' => 7]);
    $actor = (new Admin)->forceFill(['id' => '3']);

    expect(Fingerprint::for(-15_00, new Details('withdraw', $reference, 'Café/€', ['b' => 1.0, 'a' => [3, 1]], $actor)))
        ->toBe('39349f97f3dd95072da3d5d764d022272a5eacc66abe84545b11f20ac4ad0cf4')
        ->and(Fingerprint::for(15_00, new Details('deposit')))
        ->not->toBe(Fingerprint::for(-15_00, new Details('deposit')))
        ->and(Fingerprint::for(1, new Details('deposit'), ['counterparty' => 1]))
        ->not->toBe(Fingerprint::for(1, new Details('deposit')));
});

it('accepts the key through the holder', function (): void {
    $deposit = $this->user->deposit(100, idempotencyKey: 'in');
    $withdrawal = $this->user->withdraw(40, idempotencyKey: 'out');

    expect($this->user->deposit('100.00', idempotencyKey: 'in')->id)->toBe($deposit->id)
        ->and($this->user->withdraw(40.0, idempotencyKey: 'out')->id)->toBe($withdrawal->id)
        ->and($deposit->idempotency_key)->toBe('in')
        ->and($withdrawal->idempotency_key)->toBe('out')
        ->and($this->user->balance())->toBe('60.00')
        ->and($this->transactionRows())->toHaveCount(2)
        ->and(fn () => $this->user->withdraw('40.01', idempotencyKey: 'out'))->toThrow(IdempotencyConflict::class);
});
