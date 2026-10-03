<?php

declare(strict_types=1);

use Anabil\Wallet\Exceptions\InvalidReversal;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

/*
 * Real concurrency (see ConcurrencyTestCase): every worker reloads the
 * entry by id, as a separate request would, and tries to reverse it. The
 * unique index on reverses_id lets exactly one through; every other worker
 * must get InvalidReversal (already reversed), never a raw database error
 * or InsufficientFunds, whether its write failed on the index or on the
 * guarded UPDATE once the winner's debit took the money.
 */

it('reverses a deposit exactly once when eight processes try at the same time', function (bool $spare): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $deposit = $wallet->deposit(100);

    if ($spare) {
        $wallet->deposit(1000);
    }

    $outcomes = $this->inParallel(array_fill(0, 8, function () use ($deposit): string {
        try {
            WalletTransaction::query()->findOrFail($deposit->id)->reverse(description: 'Refund');

            return 'reversed';
        } catch (InvalidReversal $e) {
            return $e->reversal !== null ? 'already' : 'invalid without reversal';
        }
    }));

    $counts = array_count_values($outcomes);
    $reversals = DB::table((string) config('wallet.tables.transactions'))->where('reverses_id', $deposit->id)->get();

    expect($counts)->toEqual(['reversed' => 1, 'already' => 7])
        ->and($reversals)->toHaveCount(1)
        ->and((int) $reversals[0]->amount)->toBe(-100_00)
        ->and($this->balance($wallet->id))->toBe($spare ? 1000_00 : 0)
        ->and($this->ledger($wallet->id))->toHaveCount($spare ? 3 : 2);

    $this->assertLedgerMatchesBalance($wallet->id);
})->with(['the wallet holds only the deposit' => false, 'the wallet holds more' => true]);

it('reverses a transfer exactly once when eight processes try through either leg', function (): void {
    $sender = User::create(['name' => 'Sender'])->wallet();
    $recipient = User::create(['name' => 'Recipient'])->wallet();
    $sender->deposit(100);
    $recipient->deposit(5);
    $transfer = $sender->transferTo($recipient, 30);

    $workers = [];

    foreach (range(0, 7) as $i) {
        $workers[] = function (int $index) use ($transfer): string {
            $leg = $index % 2 === 0 ? $transfer->out->id : $transfer->in->id;

            try {
                WalletTransaction::query()->findOrFail($leg)->reverse();

                return 'reversed';
            } catch (InvalidReversal $e) {
                return $e->reversal !== null ? 'already' : 'invalid without reversal';
            }
        };
    }

    $outcomes = $this->inParallel($workers);
    $table = DB::table((string) config('wallet.tables.transactions'));
    $reversals = $table->where('type', 'reversal')->orderBy('id')->get();

    expect(array_count_values($outcomes))->toEqual(['reversed' => 1, 'already' => 7])
        ->and($reversals)->toHaveCount(2)
        ->and(array_map(fn (object $row): int => (int) $row->reverses_id, $reversals->all()))->toBe([$transfer->out->id, $transfer->in->id])
        ->and($reversals[0]->group_uuid)->not->toBeNull()
        ->and($reversals[1]->group_uuid)->toBe($reversals[0]->group_uuid)
        ->and($this->balance($sender->id))->toBe(100_00)
        ->and($this->balance($recipient->id))->toBe(5_00);

    $this->assertLedgerMatchesBalance($sender->id);
    $this->assertLedgerMatchesBalance($recipient->id);
});

it('records a concurrently repeated keyed reversal exactly once and replays it to the others', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $deposit = $wallet->deposit(100);

    $ids = $this->inParallel(array_fill(0, 8, fn (): int => WalletTransaction::query()->findOrFail($deposit->id)
        ->reverse(description: 'Refund', idempotencyKey: 'refund-once')->id));

    $rows = DB::table((string) config('wallet.tables.transactions'))->where('idempotency_key', 'refund-once')->get();

    expect($rows)->toHaveCount(1)
        ->and(array_unique($ids))->toBe([(int) $rows[0]->id])
        ->and($this->balance($wallet->id))->toBe(0);

    $this->assertLedgerMatchesBalance($wallet->id);
});
