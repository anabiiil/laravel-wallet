<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Events\TransferCompleted;
use Anabil\Wallet\Exceptions\BalanceOverflow;
use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Exceptions\InvalidTransfer;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\TransferResult;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Wallet ids follow creation order: the user's wallet is created first, so
 * user -> merchant goes from the lower id to the higher one and merchant ->
 * user the other way. A real listener is used instead of Event::fake(), so
 * the after-commit dispatch behaves as in production.
 */

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
    $this->merchant = Merchant::create(['name' => 'Shop']);
    $this->userWallet = $this->user->wallet();
    $this->merchantWallet = $this->merchant->wallet();
    $this->events = new ArrayObject;

    Event::listen(TransactionRecorded::class, function (TransactionRecorded $event): void {
        $this->events[] = ['recorded', $event->transaction->id, DB::transactionLevel()];
    });

    Event::listen(TransferCompleted::class, function (TransferCompleted $event): void {
        $this->events[] = ['completed', $event->transfer->group, DB::transactionLevel()];
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('moves money from a user to a merchant', function (): void {
    Carbon::setTestNow('2026-04-05 06:07:08');
    $this->userWallet->deposit(100_00);

    $result = $this->userWallet->transferTo($this->merchantWallet, 30_00);

    expect($result)->toBeInstanceOf(TransferResult::class)
        ->and($result->amount())->toBe(30_00)
        ->and($result->replayed())->toBeFalse()
        ->and($result->group)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($result->out->exists)->toBeTrue()
        ->and($result->out->wasRecentlyCreated)->toBeTrue()
        ->and($result->out->isDirty())->toBeFalse()
        ->and($result->out->type)->toBe(CoreType::TransferOut)
        ->and($result->out->amount)->toBe(-30_00)
        ->and($result->out->balance_after)->toBe(70_00)
        ->and($result->out->wallet_id)->toBe($this->userWallet->id)
        ->and($result->out->group_uuid)->toBe($result->group)
        ->and($result->in->type)->toBe(CoreType::TransferIn)
        ->and($result->in->amount)->toBe(30_00)
        ->and($result->in->balance_after)->toBe(30_00)
        ->and($result->in->wallet_id)->toBe($this->merchantWallet->id)
        ->and($result->in->group_uuid)->toBe($result->group)
        ->and($result->out->id)->toBeLessThan($result->in->id)
        ->and($this->userWallet->balance)->toBe(70_00)
        ->and($this->merchantWallet->balance)->toBe(30_00)
        ->and($this->userWallet->isDirty())->toBeFalse()
        ->and($this->merchantWallet->isDirty())->toBeFalse()
        ->and($this->userWallet->updated_at?->toDateTimeString())->toBe('2026-04-05 06:07:08')
        ->and($this->merchantWallet->updated_at?->toDateTimeString())->toBe('2026-04-05 06:07:08');

    $rows = $this->transactionRows();

    expect($rows)->toHaveCount(3)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(70_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(30_00)
        ->and(array_column($rows, 'type'))->toBe(['deposit', 'transfer_out', 'transfer_in'])
        ->and(array_map('intval', array_column($rows, 'amount')))->toBe([100_00, -30_00, 30_00])
        ->and(array_map('intval', array_column($rows, 'balance_after')))->toBe([100_00, 70_00, 30_00])
        ->and(array_map('intval', array_column($rows, 'wallet_id')))->toBe([$this->userWallet->id, $this->userWallet->id, $this->merchantWallet->id])
        ->and(array_column($rows, 'group_uuid'))->toBe([null, $result->group, $result->group])
        ->and((string) $rows[1]['created_at'])->toStartWith('2026-04-05 06:07:08')
        ->and((string) $rows[2]['created_at'])->toStartWith('2026-04-05 06:07:08');
});

it('moves money from a merchant to a user', function (): void {
    $this->merchantWallet->deposit(50_00);

    $result = $this->merchantWallet->transferTo($this->userWallet, 20_00);

    expect($result->out->wallet_id)->toBe($this->merchantWallet->id)
        ->and($result->out->amount)->toBe(-20_00)
        ->and($result->out->balance_after)->toBe(30_00)
        ->and($result->in->wallet_id)->toBe($this->userWallet->id)
        ->and($result->in->balance_after)->toBe(20_00)
        ->and($result->out->id)->toBeLessThan($result->in->id)
        ->and($this->merchantWallet->balance)->toBe(30_00)
        ->and($this->userWallet->balance)->toBe(20_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(30_00)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(20_00);
});

it('moves money between two admins in USD', function (): void {
    $alice = Admin::create(['name' => 'Alice'])->wallet();
    $bob = Admin::create(['name' => 'Bob'])->wallet();
    $alice->deposit(10_00);

    $result = $alice->transferTo($bob, 10_00);

    expect($alice->currency)->toBe('USD')
        ->and($bob->currency)->toBe('USD')
        ->and($alice->balance)->toBe(0)
        ->and($bob->balance)->toBe(10_00)
        ->and($result->in->balance_after)->toBe(10_00)
        ->and($result->out->balance_after)->toBe(0);
});

it('moves money between two wallets of the same holder', function (): void {
    $savings = $this->user->wallet('savings');
    $this->userWallet->deposit(40_00);

    $result = $this->userWallet->transferTo($savings, 15_00, description: 'Save');

    expect($this->user->balance())->toBe(25_00)
        ->and($this->user->balance('savings'))->toBe(15_00)
        ->and($result->in->wallet_id)->toBe($savings->id)
        ->and($result->in->description)->toBe('Save')
        ->and($result->out->description)->toBe('Save');
});

it('stores the reference, meta and actor on both entries', function (): void {
    $order = Order::create();
    $admin = Admin::create(['name' => 'Root']);
    $this->userWallet->deposit(10_00);

    $result = $this->userWallet->transferTo(
        $this->merchantWallet,
        5_00,
        reference: $order,
        description: 'Paid order',
        recipientDescription: 'Received payment',
        meta: ['order' => $order->id, 'channel' => 'web'],
        performedBy: $admin,
    );

    $rows = array_slice($this->transactionRows(), 1);

    foreach ($rows as $row) {
        expect($row['reference_type'])->toBe('order')
            ->and((int) $row['reference_id'])->toBe($order->id)
            ->and($row['performed_by_type'])->toBe('admin')
            ->and((int) $row['performed_by_id'])->toBe($admin->id)
            ->and(json_decode((string) $row['meta'], true))->toBe(['order' => $order->id, 'channel' => 'web']);
    }

    expect($rows[0]['description'])->toBe('Paid order')
        ->and($rows[1]['description'])->toBe('Received payment')
        ->and($result->out->description)->toBe('Paid order')
        ->and($result->in->description)->toBe('Received payment')
        ->and($result->in->meta)->toBe(['order' => $order->id, 'channel' => 'web']);
});

it('uses the description for the recipient unless told otherwise', function (): void {
    $this->userWallet->deposit(10_00);

    $default = $this->userWallet->transferTo($this->merchantWallet, 1_00, description: 'Rent');
    $blank = $this->userWallet->transferTo($this->merchantWallet, 1_00, description: 'Rent', recipientDescription: '  ');
    $none = $this->userWallet->transferTo($this->merchantWallet, 1_00);
    $onlyRecipient = $this->userWallet->transferTo($this->merchantWallet, 1_00, recipientDescription: 'Gift');

    expect([$default->out->description, $default->in->description])->toBe(['Rent', 'Rent'])
        ->and([$blank->out->description, $blank->in->description])->toBe(['Rent', null])
        ->and([$none->out->description, $none->in->description])->toBe([null, null])
        ->and([$onlyRecipient->out->description, $onlyRecipient->in->description])->toBe([null, 'Gift']);
});

it('validates the details like deposit() and withdraw()', function (array $args): void {
    $this->userWallet->deposit(10_00);

    expect(fn () => $this->userWallet->transferTo($this->merchantWallet, 1_00, ...$args))->toThrow(InvalidTransaction::class)
        ->and($this->transactionRows())->toHaveCount(1)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(10_00);
})->with([
    'description too long' => [['description' => str_repeat('x', 256)]],
    'recipient description too long' => [['recipientDescription' => str_repeat('x', 256)]],
    'unsaved reference' => [['reference' => new Order]],
    'unsaved actor' => [['performedBy' => new Admin]],
    'meta not JSON' => [['meta' => ['bad' => "\xff"]]],
    'empty key' => [['idempotencyKey' => ' ']],
]);

it('returns both entries with their relations preloaded', function (): void {
    $order = Order::create();
    $admin = Admin::create(['name' => 'Root']);
    $this->userWallet->deposit(10_00);

    $result = $this->userWallet->transferTo($this->merchantWallet, 1_00, reference: $order, performedBy: $admin);
    $plain = $this->userWallet->transferTo($this->merchantWallet, 1_00);

    $queries = $this->queriesDuring(function () use ($result, $plain, $order, $admin): void {
        expect($result->out->wallet)->toBe($this->userWallet)
            ->and($result->in->wallet)->toBe($this->merchantWallet)
            ->and($result->out->reference)->toBe($order)
            ->and($result->in->reference)->toBe($order)
            ->and($result->out->performedBy)->toBe($admin)
            ->and($result->in->performedBy)->toBe($admin)
            ->and($plain->out->reference)->toBeNull()
            ->and($plain->in->performedBy)->toBeNull();
    });

    expect($queries)->toBe([]);
});

it('finds the counterpart of each entry', function (): void {
    $this->userWallet->deposit(10_00);
    $result = $this->userWallet->transferTo($this->merchantWallet, 4_00);
    $deposit = $this->userWallet->transactions()->ofType(CoreType::Deposit)->firstOrFail();

    $out = WalletTransaction::query()->findOrFail($result->out->id);
    $in = WalletTransaction::query()->findOrFail($result->in->id);

    expect($out->counterpart()?->id)->toBe($in->id)
        ->and($in->counterpart()?->id)->toBe($out->id)
        ->and($result->out->counterpart()?->id)->toBe($in->id)
        ->and($in->counterpart())->toBeInstanceOf(WalletTransaction::class)
        ->and($deposit->counterpart())->toBeNull();
});

it('queries both legs of transfers', function (): void {
    $this->userWallet->deposit(10_00);
    $this->userWallet->withdraw(1_00);
    $result = $this->userWallet->transferTo($this->merchantWallet, 4_00);

    expect(WalletTransaction::query()->transfers()->orderBy('id')->pluck('id')->all())->toBe([$result->out->id, $result->in->id])
        ->and($this->userWallet->transactions()->transfers()->pluck('id')->all())->toBe([$result->out->id])
        ->and($this->merchantWallet->transactions()->transfers()->credits()->count())->toBe(1);
});

it('keeps every ledger consistent and conserves money over a mixed sequence', function (): void {
    $savings = $this->user->wallet('savings');
    $wallets = [$this->userWallet, $this->merchantWallet, $savings];

    $this->userWallet->deposit(200_00);
    $this->merchantWallet->deposit(50_00);
    $this->userWallet->transferTo($this->merchantWallet, 30_00);
    $this->merchantWallet->transferTo($savings, 120_00);
    $savings->transferTo($this->userWallet, 20_00);
    $this->userWallet->withdraw(5_00);
    $this->merchantWallet->transferTo($this->userWallet, 55_00);
    $savings->withdraw(1_00);
    $this->user->transferTo($this->merchant, 3_33);

    $total = 0;

    foreach ($wallets as $wallet) {
        $balance = (int) $this->walletRow($wallet->id)['balance'];
        $running = 0;

        foreach (array_filter($this->transactionRows(), fn (array $row): bool => (int) $row['wallet_id'] === $wallet->id) as $row) {
            $running += (int) $row['amount'];

            expect((int) $row['balance_after'])->toBe($running);
        }

        expect($this->ledgerSum($wallet->id))->toBe($balance)
            ->and($wallet->refresh()->balance)->toBe($balance);

        $total += $balance;
    }

    expect($total)->toBe(200_00 + 50_00 - 5_00 - 1_00)
        ->and($this->merchantWallet->balance)->toBe(-91_67)
        ->and((int) DB::table($this->transactionsTable())->whereIn('type', ['transfer_in', 'transfer_out'])->sum('amount'))->toBe(0);
});

it('refuses a transfer the sender cannot cover without writing anything', function (bool $senderFirst): void {
    $low = User::create(['name' => 'Low'])->wallet();
    $high = User::create(['name' => 'High'])->wallet();
    $low->deposit(10_00);
    $high->deposit(10_00);

    [$sender, $recipient] = $senderFirst ? [$low, $high] : [$high, $low];
    $snapshot = $this->transactionRows();
    $this->events->exchangeArray([]);

    $queries = [];

    try {
        $queries = $this->queriesDuring(fn () => $sender->transferTo($recipient, 10_01));
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->wallet)->toBe($sender)
            ->and($e->requested)->toBe(10_01)
            ->and($e->balance)->toBe(10_00)
            ->and($e->available)->toBe(10_00);
    }

    expect($this->transactionRows())->toBe($snapshot)
        ->and((int) $this->walletRow($sender->id)['balance'])->toBe(10_00)
        ->and((int) $this->walletRow($recipient->id)['balance'])->toBe(10_00)
        ->and($sender->balance)->toBe(10_00)
        ->and($recipient->balance)->toBe(10_00)
        ->and($sender->isDirty())->toBeFalse()
        ->and($recipient->isDirty())->toBeFalse()
        ->and($this->events->getArrayCopy())->toBe([]);
})->with([
    'sender has the lower id' => [true],
    'recipient has the lower id (its credit is applied, then rolled back)' => [false],
]);

it('applies the credit before the refused debit when the recipient has the lower id', function (): void {
    $low = User::create(['name' => 'Low'])->wallet();
    $high = User::create(['name' => 'High'])->wallet();

    $updates = [];

    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $updates[] = $query->bindings;
        }
    });

    expect(fn () => $high->transferTo($low, 1))->toThrow(InsufficientFunds::class);

    // Both UPDATEs ran (the credit on the lower id succeeded, the debit on
    // the higher id matched no row), and the rollback undid the credit.
    expect($updates)->toHaveCount(2)
        ->and(in_array($low->id, $updates[0], true))->toBeTrue()
        ->and(in_array($high->id, $updates[1], true))->toBeTrue()
        ->and((int) $this->walletRow($low->id)['balance'])->toBe(0)
        ->and($this->transactionRows())->toBe([]);
});

it('lets a credit line sender go negative down to its floor', function (): void {
    $result = $this->merchantWallet->transferTo($this->userWallet, 100_00);

    expect($result->out->balance_after)->toBe(-100_00)
        ->and($this->merchantWallet->balance)->toBe(-100_00)
        ->and($this->userWallet->balance)->toBe(100_00)
        ->and(fn () => $this->merchantWallet->transferTo($this->userWallet, 1))->toThrow(InsufficientFunds::class)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(-100_00)
        ->and($this->transactionRows())->toHaveCount(2);
});

it('credits a recipient that is below its own floor', function (): void {
    $this->userWallet->setMinBalance(-500);
    $this->userWallet->withdraw(300);
    $this->userWallet->setMinBalance(0);

    $result = $this->merchantWallet->transferTo($this->userWallet, 100);

    expect($result->in->balance_after)->toBe(-200)
        ->and($this->userWallet->balance)->toBe(-200)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(-200);
});

it('refuses a transfer that would overflow the recipient without writing anything', function (): void {
    $this->userWallet->deposit(100);
    $this->forceBalance($this->merchantWallet->id, PHP_INT_MAX - 10);
    $snapshot = $this->transactionRows();

    expect(fn () => $this->userWallet->transferTo($this->merchantWallet, 11))->toThrow(BalanceOverflow::class)
        ->and($this->transactionRows())->toBe($snapshot)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(100)
        ->and($this->walletRow($this->merchantWallet->id)['balance'])->toEqual(PHP_INT_MAX - 10)
        ->and($this->userWallet->balance)->toBe(100);

    $result = $this->userWallet->transferTo($this->merchantWallet, 10);

    expect($result->in->balance_after)->toBe(PHP_INT_MAX);
});

it('refuses a transfer to the same wallet', function (): void {
    $this->userWallet->deposit(100);
    $same = Wallet::findOrFail($this->userWallet->id);

    $queries = $this->queriesDuring(function () use ($same): void {
        expect(fn () => $this->userWallet->transferTo($this->userWallet, 1))->toThrow(InvalidTransfer::class, 'to itself')
            ->and(fn () => $this->userWallet->transferTo($same, 1))->toThrow(InvalidTransfer::class, 'to itself');
    });

    expect($queries)->toBe([])
        ->and($this->transactionRows())->toHaveCount(1);
});

it('refuses a transfer between currencies', function (): void {
    $usd = Admin::create(['name' => 'Root'])->wallet();
    $this->userWallet->deposit(100);

    expect(fn () => $this->userWallet->transferTo($usd, 1))->toThrow(InvalidTransfer::class, 'same currency')
        ->and(fn () => $usd->transferTo($this->userWallet, 1))->toThrow(InvalidTransfer::class, 'same currency')
        ->and($this->transactionRows())->toHaveCount(1);
});

it('refuses a transfer between connections before running any query', function (): void {
    $elsewhere = Wallet::findOrFail($this->merchantWallet->id)->setConnection('other');

    $queries = $this->queriesDuring(function () use ($elsewhere): void {
        expect(fn () => $this->userWallet->transferTo($elsewhere, 1))->toThrow(InvalidTransfer::class, 'same database connection')
            ->and(fn () => $elsewhere->transferTo($this->userWallet, 1))->toThrow(InvalidTransfer::class, 'connection [other]');
    });

    expect($queries)->toBe([]);
});

it('treats an unset connection as the default one', function (): void {
    $default = DB::getDefaultConnection();
    $explicit = Wallet::findOrFail($this->merchantWallet->id)->setConnection($default);
    $implicit = Wallet::findOrFail($this->userWallet->id)->setConnection(null);
    $implicit->deposit(10);

    $result = $implicit->transferTo($explicit, 10);

    expect($result->in->balance_after)->toBe(10);
});

it('rejects non-positive amounts', function (int $amount): void {
    $this->userWallet->deposit(100);

    expect(fn () => $this->userWallet->transferTo($this->merchantWallet, $amount))->toThrow(InvalidAmount::class)
        ->and($this->transactionRows())->toHaveCount(1);
})->with([0, -1, PHP_INT_MIN]);

it('refuses unsaved wallets', function (): void {
    expect(fn () => $this->userWallet->transferTo(new Wallet, 1))->toThrow(WalletException::class, 'unsaved wallet')
        ->and(fn () => (new Wallet)->transferTo($this->userWallet, 1))->toThrow(WalletException::class, 'unsaved wallet');
});

it('transfers from a holder to another holder, creating its default wallet', function (): void {
    $bob = User::create(['name' => 'Bob']);
    $this->user->deposit(10_00);

    expect($bob->hasWallet())->toBeFalse();

    $result = $this->user->transferTo($bob, 4_00, description: 'Lunch');

    expect($bob->hasWallet())->toBeTrue()
        ->and($bob->balance())->toBe(4_00)
        ->and($this->user->balance())->toBe(6_00)
        ->and($result->in->wallet_id)->toBe($bob->wallet()->id)
        ->and($result->out->wallet_id)->toBe($this->userWallet->id)
        ->and($result->in->description)->toBe('Lunch');
});

it('transfers from a holder to a given wallet', function (): void {
    $ops = $this->merchant->wallet('ops');
    $this->user->deposit(10_00);

    $result = $this->user->transferTo($ops, 1_00, idempotencyKey: 'k');

    expect($result->in->wallet)->toBe($ops)
        ->and($ops->balance)->toBe(1_00)
        ->and($this->merchant->balance())->toBe(0)
        ->and($this->user->transferTo($ops, 1_00, idempotencyKey: 'k')->replayed())->toBeTrue();
});

it('refuses a recipient model that holds no wallets', function (): void {
    $this->user->deposit(10_00);

    expect(fn () => $this->user->transferTo(Order::create(), 1))->toThrow(InvalidTransfer::class, 'HasWallet')
        ->and($this->transactionRows())->toHaveCount(1);
});

it('locks the wallet with the lower id first in both directions', function (): void {
    $this->userWallet->deposit(10_00);
    $this->merchantWallet->deposit(10_00);

    expect($this->userWallet->id)->toBeLessThan($this->merchantWallet->id);

    $updates = [];

    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $updates[] = $query->bindings;
        }
    });

    $this->userWallet->transferTo($this->merchantWallet, 1_00);
    $this->merchantWallet->transferTo($this->userWallet, 2_00);

    $low = $this->userWallet->id;
    $high = $this->merchantWallet->id;

    expect($updates)->toHaveCount(4)
        // user -> merchant: the debit (lower id) first.
        ->and(in_array($low, $updates[0], true) && ! in_array($high, $updates[0], true))->toBeTrue()
        ->and(in_array($high, $updates[1], true) && ! in_array($low, $updates[1], true))->toBeTrue()
        // merchant -> user: the credit (lower id) first.
        ->and(in_array($low, $updates[2], true) && ! in_array($high, $updates[2], true))->toBeTrue()
        ->and(in_array($high, $updates[3], true) && ! in_array($low, $updates[3], true))->toBeTrue();
});

it('runs a fixed number of queries per transfer', function (): void {
    $this->userWallet->deposit(10_00);

    // Two guarded UPDATEs (lower wallet id first), each followed by the
    // read of the balance it produced, then the sender's and the
    // recipient's INSERT. Transaction statements are not queries.
    $queries = $this->queriesDuring(fn () => $this->userWallet->transferTo($this->merchantWallet, 1_00));

    expect($queries)->toHaveCount(6)
        ->and($queries[0])->toStartWith('update')
        ->and($queries[1])->toStartWith('select')
        ->and($queries[2])->toStartWith('update')
        ->and($queries[3])->toStartWith('select')
        ->and($queries[4])->toStartWith('insert')
        ->and($queries[5])->toStartWith('insert');

    // With a key: one lookup first.
    $keyed = $this->queriesDuring(fn () => $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k'));

    expect($keyed)->toHaveCount(7)
        ->and($keyed[0])->toStartWith('select')
        ->and($keyed[0])->toContain('idempotency_key')
        ->and(array_slice($keyed, 1))->toHaveCount(6);

    // A replay: the lookup and the recipient's entry.
    $replay = $this->queriesDuring(fn () => $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k'));

    expect($replay)->toHaveCount(2)
        ->and($replay[0])->toContain('idempotency_key')
        ->and($replay[1])->toContain('group_uuid');
});

it('dispatches an event per entry and then TransferCompleted', function (): void {
    $this->userWallet->deposit(10_00);
    $this->events->exchangeArray([]);

    $result = $this->userWallet->transferTo($this->merchantWallet, 1_00);

    expect(new TransferCompleted($result))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(array_map(fn (array $event): array => array_slice($event, 0, 2), $this->events->getArrayCopy()))->toBe([
            ['recorded', $result->out->id],
            ['recorded', $result->in->id],
            ['completed', $result->group],
        ]);
});

it('passes the result to TransferCompleted listeners', function (): void {
    $received = null;

    Event::listen(TransferCompleted::class, function (TransferCompleted $event) use (&$received): void {
        $received = $event->transfer;
    });

    $result = $this->merchantWallet->transferTo($this->userWallet, 1);

    expect($received)->toBe($result);
});

it('dispatches nothing when the transfer fails or is replayed', function (): void {
    $this->userWallet->deposit(10_00);
    $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k');
    $this->events->exchangeArray([]);

    expect(fn () => $this->userWallet->transferTo($this->merchantWallet, 100_00))->toThrow(InsufficientFunds::class)
        ->and(fn () => $this->userWallet->transferTo($this->userWallet, 1))->toThrow(InvalidTransfer::class)
        ->and($this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k')->replayed())->toBeTrue()
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('waits for the outer transaction to commit before dispatching', function (): void {
    $baseLevel = DB::transactionLevel();
    $this->userWallet->deposit(10_00);
    $this->events->exchangeArray([]);

    DB::transaction(function (): void {
        $this->userWallet->transferTo($this->merchantWallet, 1_00);

        expect($this->events->getArrayCopy())->toBe([]);
    });

    expect($this->events->getArrayCopy())->toHaveCount(3)
        ->and(array_column($this->events->getArrayCopy(), 2))->toBe([$baseLevel, $baseLevel, $baseLevel])
        ->and(array_column($this->events->getArrayCopy(), 0))->toBe(['recorded', 'recorded', 'completed']);
});

it('never dispatches and restores both instances when the outer transaction rolls back', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $this->userWallet->deposit(10_00);
    $this->merchantWallet->deposit(1_00);
    $before = $this->transactionRows();
    $this->events->exchangeArray([]);

    Carbon::setTestNow('2026-02-01 00:00:00');

    try {
        DB::transaction(function (): void {
            $this->userWallet->transferTo($this->merchantWallet, 3_00);
            $this->merchantWallet->transferTo($this->userWallet, 1_00);

            expect($this->userWallet->balance)->toBe(8_00)
                ->and($this->merchantWallet->balance)->toBe(3_00);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($this->transactionRows())->toBe($before)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(10_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(1_00)
        ->and($this->userWallet->balance)->toBe(10_00)
        ->and($this->merchantWallet->balance)->toBe(1_00)
        ->and($this->userWallet->updated_at?->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($this->merchantWallet->updated_at?->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($this->userWallet->isDirty())->toBeFalse()
        ->and($this->merchantWallet->isDirty())->toBeFalse()
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('replays a transfer made with the same key', function (): void {
    $this->userWallet->deposit(10_00);
    $first = $this->userWallet->transferTo($this->merchantWallet, 4_00, description: 'Rent', idempotencyKey: 'rent-1');

    $sender = Wallet::findOrFail($this->userWallet->id);
    $recipient = Wallet::findOrFail($this->merchantWallet->id);
    $this->events->exchangeArray([]);

    $again = $sender->transferTo($recipient, 4_00, description: 'Rent', idempotencyKey: 'rent-1');

    expect($again->replayed())->toBeTrue()
        ->and($again->out->id)->toBe($first->out->id)
        ->and($again->in->id)->toBe($first->in->id)
        ->and($again->group)->toBe($first->group)
        ->and($again->amount())->toBe(4_00)
        ->and($again->out->wasRecentlyCreated)->toBeFalse()
        ->and($again->out->wallet)->toBe($sender)
        ->and($again->in->wallet)->toBe($recipient)
        ->and($again->in->description)->toBe('Rent')
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(6_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(4_00)
        ->and($this->transactionRows())->toHaveCount(3)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('does not touch the instances on replay', function (): void {
    $this->userWallet->deposit(10_00);
    $this->userWallet->transferTo($this->merchantWallet, 4_00, idempotencyKey: 'k');

    $sender = Wallet::findOrFail($this->userWallet->id);
    $recipient = Wallet::findOrFail($this->merchantWallet->id);
    $this->userWallet->deposit(1_00);
    $this->merchantWallet->deposit(1_00);

    $sender->transferTo($recipient, 4_00, idempotencyKey: 'k');

    expect($sender->balance)->toBe(6_00)
        ->and($recipient->balance)->toBe(4_00)
        ->and($sender->isDirty())->toBeFalse()
        ->and($recipient->isDirty())->toBeFalse();
});

it('replays a transfer the sender can no longer cover', function (): void {
    $this->userWallet->deposit(4_00);
    $first = $this->userWallet->transferTo($this->merchantWallet, 4_00, idempotencyKey: 'k');

    expect($this->userWallet->transferTo($this->merchantWallet, 4_00, idempotencyKey: 'k')->out->id)->toBe($first->out->id)
        ->and($this->userWallet->balance)->toBe(0);
});

it('stores the key on the sender entry only', function (): void {
    $this->userWallet->deposit(10_00);

    $result = $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k');

    $rows = $this->transactionRows();

    expect($rows[1]['idempotency_key'])->toBe('k')
        ->and($rows[1]['idempotency_hash'])->toBe($this->transferFingerprint($this->merchantWallet, 1_00))
        ->and($rows[2]['idempotency_key'])->toBeNull()
        ->and($rows[2]['idempotency_hash'])->toBeNull()
        ->and($result->out->idempotency_key)->toBe('k')
        ->and($result->in->idempotency_key)->toBeNull();
});

it('refuses to reuse a transfer key for a different operation', function (string $change): void {
    $order = Order::create();
    $admin = Admin::create(['name' => 'Root']);
    $other = User::create(['name' => 'Other'])->wallet();
    $this->userWallet->deposit(100_00);

    $args = [
        'recipient' => $this->merchantWallet,
        'amount' => 10_00,
        'reference' => $order,
        'description' => 'Pay',
        'recipientDescription' => 'Paid',
        'meta' => ['a' => 1],
        'performedBy' => $admin,
        'idempotencyKey' => 'k',
    ];

    $first = $this->userWallet->transferTo(...$args);
    $snapshot = $this->transactionRows();

    match ($change) {
        'amount' => $args['amount'] = 10_01,
        'recipient' => $args['recipient'] = $other,
        'description' => $args['description'] = 'Pay!',
        'recipientDescription' => $args['recipientDescription'] = 'Paid!',
        'no recipientDescription' => $args['recipientDescription'] = null,
        'meta' => $args['meta'] = ['a' => 2],
        'reference' => $args['reference'] = Order::create(),
        'performedBy' => $args['performedBy'] = Admin::create(['name' => 'Other']),
    };

    try {
        $this->userWallet->transferTo(...$args);
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->key)->toBe('k')
            ->and($e->walletId)->toBe($this->userWallet->id)
            ->and($e->transactionId)->toBe($first->out->id)
            ->and($e->getMessage())->toContain('different operation');
    }

    expect($this->transactionRows())->toBe($snapshot)
        ->and($this->userWallet->balance)->toBe(90_00);
})->with([
    'amount', 'recipient', 'description', 'recipientDescription', 'no recipientDescription', 'meta', 'reference', 'performedBy',
]);

it('lets two senders use the same key towards the same recipient', function (): void {
    $bob = User::create(['name' => 'Bob'])->wallet();
    $this->userWallet->deposit(10_00);
    $bob->deposit(10_00);

    $a = $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'order:5');
    $b = $bob->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'order:5');

    expect($a->replayed())->toBeFalse()
        ->and($b->replayed())->toBeFalse()
        ->and($a->in->id)->not->toBe($b->in->id)
        ->and($this->merchantWallet->refresh()->balance)->toBe(2_00)
        ->and(DB::table($this->transactionsTable())->where('idempotency_key', 'order:5')->count())->toBe(2);
});

it('keeps the recipient free to use the same key', function (): void {
    $this->userWallet->deposit(10_00);
    $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k');

    // The recipient's entry carries no key, so 'k' is still free there.
    expect($this->merchantWallet->transferTo($this->userWallet, 5, idempotencyKey: 'k')->replayed())->toBeFalse()
        ->and(DB::table($this->transactionsTable())->where('idempotency_key', 'k')->count())->toBe(2);
});

it('shares the key space of the sender with deposits and withdrawals', function (): void {
    $deposit = $this->userWallet->deposit(10_00, idempotencyKey: 'dep');
    $transfer = $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'tr');

    try {
        $this->userWallet->transferTo($this->merchantWallet, 10_00, idempotencyKey: 'dep');
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->transactionId)->toBe($deposit->id);
    }

    expect(fn () => $this->userWallet->withdraw(1_00, idempotencyKey: 'tr'))->toThrow(IdempotencyConflict::class)
        ->and(fn () => $this->userWallet->deposit(1_00, idempotencyKey: 'tr'))->toThrow(IdempotencyConflict::class)
        ->and($transfer->out->idempotency_key)->toBe('tr')
        ->and($this->transactionRows())->toHaveCount(3);
});

it('does not consume the key when the transfer fails', function (): void {
    expect(fn () => $this->userWallet->transferTo($this->merchantWallet, 5_00, idempotencyKey: 'k'))->toThrow(InsufficientFunds::class)
        ->and($this->transactionRows())->toBe([]);

    $this->userWallet->deposit(5_00);

    $result = $this->userWallet->transferTo($this->merchantWallet, 5_00, idempotencyKey: 'k');

    expect($result->replayed())->toBeFalse()
        ->and($this->userWallet->transferTo($this->merchantWallet, 5_00, idempotencyKey: 'k')->out->id)->toBe($result->out->id);
});

it('refuses to replay a key whose transfer is incomplete', function (string $case): void {
    $group = '0190a7a0-0000-7000-8000-000000000000';

    $id = $this->insertTransactionRow($this->userWallet->id, [
        'type' => $case === 'not a transfer_out' ? 'transfer_in' : 'transfer_out',
        'amount' => -1_00,
        'balance_after' => -1_00,
        'idempotency_key' => 'k',
        'idempotency_hash' => $this->transferFingerprint($this->merchantWallet, 1_00),
        'group_uuid' => $case === 'no group' ? null : $group,
    ]);

    try {
        $this->userWallet->transferTo($this->merchantWallet, 1_00, idempotencyKey: 'k');
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->transactionId)->toBe($id)
            ->and($e->getMessage())->toContain('not part of a complete transfer');
    }

    expect($this->transactionRows())->toHaveCount(1);
})->with(['missing recipient entry', 'no group', 'not a transfer_out']);

it('returns the winner of a race with a matching transfer', function (): void {
    $this->userWallet->deposit(10_00);
    $this->events->exchangeArray([]);
    $winner = null;

    $this->afterIdempotencyLookup(function () use (&$winner): void {
        $winner = $this->competingTransfer($this->userWallet, $this->merchantWallet, 3_00, 'race');
    });

    $result = $this->userWallet->transferTo($this->merchantWallet, 3_00, idempotencyKey: 'race');

    expect($winner)->not->toBeNull()
        ->and($result->replayed())->toBeTrue()
        ->and([$result->out->id, $result->in->id])->toBe($winner)
        ->and($result->out->wallet)->toBe($this->userWallet)
        ->and($result->in->wallet)->toBe($this->merchantWallet)
        ->and((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(7_00)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(3_00)
        ->and($this->ledgerSum($this->userWallet->id))->toBe(7_00)
        ->and($this->ledgerSum($this->merchantWallet->id))->toBe(3_00)
        ->and($this->transactionRows())->toHaveCount(3)
        // Untouched: the instances never saw the winner's change.
        ->and($this->userWallet->balance)->toBe(10_00)
        ->and($this->merchantWallet->balance)->toBe(0)
        ->and($this->events->getArrayCopy())->toBe([]);
});

it('throws a conflict and rolls back when the race winner did something else', function (): void {
    $this->userWallet->deposit(10_00);

    $this->afterIdempotencyLookup(function (): void {
        $this->competingTransfer($this->userWallet, $this->merchantWallet, 99, 'race', str_repeat('0', 64));
    });

    try {
        $this->userWallet->transferTo($this->merchantWallet, 3_00, idempotencyKey: 'race');
        $this->fail('Expected IdempotencyConflict.');
    } catch (IdempotencyConflict $e) {
        expect($e->key)->toBe('race')
            ->and($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class);
    }

    expect((int) $this->walletRow($this->userWallet->id)['balance'])->toBe(9_01)
        ->and((int) $this->walletRow($this->merchantWallet->id)['balance'])->toBe(99)
        ->and($this->ledgerSum($this->userWallet->id))->toBe(9_01)
        ->and($this->ledgerSum($this->merchantWallet->id))->toBe(99)
        ->and($this->transactionRows())->toHaveCount(3);
});

it('recovers from a transfer race inside an outer transaction and keeps it usable', function (): void {
    $this->userWallet->deposit(10_00);
    $this->events->exchangeArray([]);
    $winner = null;

    $this->afterIdempotencyLookup(function () use (&$winner): void {
        $winner = $this->competingTransfer($this->userWallet, $this->merchantWallet, 2_00, 'race');
    });

    [$replayed, $next] = DB::transaction(fn (): array => [
        $this->userWallet->transferTo($this->merchantWallet, 2_00, idempotencyKey: 'race'),
        $this->userWallet->transferTo($this->merchantWallet, 1_00),
    ]);

    expect($replayed->replayed())->toBeTrue()
        ->and([$replayed->out->id, $replayed->in->id])->toBe($winner)
        ->and($next->replayed())->toBeFalse()
        ->and($next->out->balance_after)->toBe(7_00)
        ->and($next->in->balance_after)->toBe(3_00)
        ->and($this->userWallet->balance)->toBe(7_00)
        ->and($this->merchantWallet->balance)->toBe(3_00)
        ->and($this->ledgerSum($this->userWallet->id))->toBe(7_00)
        ->and($this->ledgerSum($this->merchantWallet->id))->toBe(3_00)
        ->and($this->transactionRows())->toHaveCount(5)
        ->and(array_column($this->events->getArrayCopy(), 1))->toBe([$next->out->id, $next->in->id, $next->group]);
});
