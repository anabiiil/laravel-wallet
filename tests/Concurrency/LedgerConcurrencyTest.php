<?php

declare(strict_types=1);

use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

/*
 * Real concurrency: every worker runs in its own forked process with its
 * own database connection, and all of them start at the same moment. See
 * ConcurrencyTestCase for the fork and database setup. The workers reload
 * their wallet by id, as a separate request would. Amounts are passed in
 * major units; balances are checked as stored, in minor units.
 */

it('lets exactly as many concurrent withdrawals through as the balance covers', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $wallet->deposit(100);

    $outcomes = $this->inParallel(array_fill(0, 10, function () use ($wallet): string {
        try {
            Wallet::query()->findOrFail($wallet->id)->withdraw('30.00');

            return 'withdrawn';
        } catch (InsufficientFunds) {
            return 'insufficient';
        }
    }));

    $counts = array_count_values($outcomes);

    expect($counts['withdrawn'] ?? 0)->toBe(3)
        ->and($counts['insufficient'] ?? 0)->toBe(7)
        ->and($this->balance($wallet->id))->toBe(10_00)
        ->and(min(array_map(fn (array $row): int => (int) $row['balance_after'], $this->ledger($wallet->id))))->toBeGreaterThanOrEqual(0)
        ->and($this->ledger($wallet->id))->toHaveCount(4);

    $this->assertLedgerMatchesBalance($wallet->id);
});

it('runs opposite transfers concurrently without deadlocks and conserves money', function (): void {
    $a = User::create(['name' => 'A'])->wallet();
    $b = User::create(['name' => 'B'])->wallet();
    $a->deposit(1000);
    $b->deposit(1000);

    $transfersPerWorker = 15;

    $workers = [];

    foreach (range(0, 9) as $i) {
        $workers[] = function (int $index) use ($a, $b, $transfersPerWorker): int {
            [$from, $to] = $index % 2 === 0 ? [$a->id, $b->id] : [$b->id, $a->id];
            $sender = Wallet::query()->findOrFail($from);
            $recipient = Wallet::query()->findOrFail($to);
            $moved = 0;

            for ($n = 1; $n <= $transfersPerWorker; $n++) {
                // In minor units, sent as a decimal string ("1.11", "1.12"...).
                $amount = 1_00 + $index * 10 + $n;
                $sender->transferTo($recipient, Amount::format($amount, 2), description: "worker {$index} #{$n}");
                $moved += $amount;
            }

            return $moved;
        };
    }

    $moved = $this->inParallel($workers);

    $aToB = array_sum(array_filter($moved, fn (int $key): bool => $key % 2 === 0, ARRAY_FILTER_USE_KEY));
    $bToA = array_sum(array_filter($moved, fn (int $key): bool => $key % 2 === 1, ARRAY_FILTER_USE_KEY));

    expect($this->balance($a->id) + $this->balance($b->id))->toBe(2_000_00)
        ->and($this->balance($a->id))->toBe(1_000_00 - $aToB + $bToA)
        ->and($this->balance($b->id))->toBe(1_000_00 + $aToB - $bToA)
        ->and($this->ledger($a->id))->toHaveCount(1 + 10 * $transfersPerWorker)
        ->and(DB::table((string) config('wallet.tables.transactions'))->whereNotNull('group_uuid')->distinct()->count('group_uuid'))->toBe(10 * $transfersPerWorker);

    $this->assertLedgerMatchesBalance($a->id);
    $this->assertLedgerMatchesBalance($b->id);
});

it('records a concurrently repeated keyed deposit exactly once', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $wallet->deposit('0.07');

    $ids = $this->inParallel(array_fill(0, 8, fn (): int => Wallet::query()->findOrFail($wallet->id)->deposit(50, idempotencyKey: 'same')->id));

    $rows = DB::table((string) config('wallet.tables.transactions'))->where('idempotency_key', 'same')->get();

    expect($rows)->toHaveCount(1)
        ->and(array_unique($ids))->toBe([(int) $rows[0]->id])
        ->and($this->balance($wallet->id))->toBe(50_07)
        ->and($this->ledger($wallet->id))->toHaveCount(2);

    $this->assertLedgerMatchesBalance($wallet->id);
});

it('records a concurrently repeated keyed transfer exactly once', function (): void {
    $sender = User::create(['name' => 'Sender'])->wallet();
    $recipient = User::create(['name' => 'Recipient'])->wallet();
    $sender->deposit(100);

    $results = $this->inParallel(array_fill(0, 8, function () use ($sender, $recipient): array {
        $result = Wallet::query()->findOrFail($sender->id)
            ->transferTo(Wallet::query()->findOrFail($recipient->id), 10.0, description: 'Once', idempotencyKey: 'same-transfer');

        return [$result->out->id, $result->in->id, $result->group, $result->replayed()];
    }));

    $originals = array_filter($results, fn (array $result): bool => $result[3] === false);

    expect(array_unique(array_map(fn (array $result): string => implode('|', array_slice($result, 0, 3)), $results)))->toHaveCount(1)
        ->and($originals)->toHaveCount(1)
        ->and(DB::table((string) config('wallet.tables.transactions'))->where('idempotency_key', 'same-transfer')->count())->toBe(1)
        ->and(DB::table((string) config('wallet.tables.transactions'))->where('group_uuid', $results[0][2])->count())->toBe(2)
        ->and($this->balance($sender->id))->toBe(90_00)
        ->and($this->balance($recipient->id))->toBe(10_00);

    $this->assertLedgerMatchesBalance($sender->id);
    $this->assertLedgerMatchesBalance($recipient->id);
});
