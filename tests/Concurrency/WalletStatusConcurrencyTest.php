<?php

declare(strict_types=1);

use Anabil\Wallet\Audit\WalletAuditor;
use Anabil\Wallet\Exceptions\InvalidStatusTransition;
use Anabil\Wallet\Exceptions\WalletClosed;
use Anabil\Wallet\Exceptions\WalletFrozen;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

/*
 * Real concurrency (see ConcurrencyTestCase): status changes race money
 * movements on the same row. The status is checked inside the money
 * UPDATE's WHERE clause and changed by a single guarded UPDATE, so the row
 * lock orders them: once a freeze or close commits, nothing it forbids can
 * pass, and a close can never land on a wallet holding money. Any other
 * exception fails the child, and so the test.
 */

it('lets no withdrawal through once a concurrent freeze has committed', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();
    $wallet->deposit(100_000);

    $withdrawers = 6;
    $workers = [];

    foreach (range(1, $withdrawers) as $i) {
        // Withdraw until refused, then try a few more times: every attempt
        // after the first refusal must be refused too.
        $workers[] = function () use ($wallet): array {
            $instance = Wallet::query()->findOrFail($wallet->id);
            $log = [];
            $refusedAfterFreeze = 0;

            for ($n = 0; $n < 5_000 && $refusedAfterFreeze < 5; $n++) {
                try {
                    $instance->withdraw(1);
                    $log[] = 'ok';
                } catch (WalletFrozen) {
                    $log[] = 'frozen';
                    $refusedAfterFreeze++;
                }
            }

            return $log;
        };
    }

    $workers[] = function () use ($wallet): string {
        usleep(50_000);

        Wallet::query()->findOrFail($wallet->id)->freeze('Race');

        return 'froze';
    };

    $results = $this->inParallel($workers);
    $freezer = array_pop($results);
    $successes = 0;

    foreach ($results as $index => $log) {
        $firstRefusal = array_search('frozen', $log, true);

        expect($firstRefusal)->not->toBeFalse("worker {$index} never saw the freeze")
            ->and(array_slice($log, (int) $firstRefusal))->each->toBe('frozen');

        $successes += count(array_keys($log, 'ok', true));
    }

    $row = DB::table((string) config('wallet.tables.wallets'))->where('id', $wallet->id)->first();

    expect($freezer)->toBe('froze')
        ->and($row?->status)->toBe('frozen')
        ->and($this->balance($wallet->id))->toBe(100_000_00 - $successes * 100)
        ->and($this->ledger($wallet->id))->toHaveCount(1 + $successes)
        ->and($this->app->make(WalletAuditor::class)->audit()->passed())->toBeTrue();

    $this->assertLedgerMatchesBalance($wallet->id);
});

it('never closes a wallet that concurrent deposits are funding', function (int $closerDelay, int $depositorDelay): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();

    $workers = [];

    foreach (range(1, 5) as $i) {
        $workers[] = function () use ($wallet, $depositorDelay): array {
            $instance = Wallet::query()->findOrFail($wallet->id);
            $log = [];

            usleep($depositorDelay);

            for ($n = 0; $n < 20; $n++) {
                try {
                    $instance->deposit(1);
                    $log[] = 'ok';
                } catch (WalletClosed) {
                    $log[] = 'closed';
                }
            }

            return $log;
        };
    }

    $workers[] = function () use ($wallet, $closerDelay): string {
        usleep($closerDelay);

        try {
            Wallet::query()->findOrFail($wallet->id)->close();

            return 'closed';
        } catch (InvalidStatusTransition $e) {
            return str_contains($e->getMessage(), 'only an empty wallet can be closed') ? 'non-zero' : 'unexpected: '.$e->getMessage();
        }
    };

    $results = $this->inParallel($workers);
    $closer = array_pop($results);
    $logs = array_merge(...$results);
    $deposits = count(array_keys($logs, 'ok', true));
    $status = DB::table((string) config('wallet.tables.wallets'))->where('id', $wallet->id)->value('status');

    expect($closer)->toBeIn(['closed', 'non-zero'])
        ->and($this->app->make(WalletAuditor::class)->audit()->passed())->toBeTrue()
        ->and($this->balance($wallet->id))->toBe($deposits * 100);

    if ($closer === 'closed') {
        // The balance was zero when it closed, and deposits only add: none
        // got in before, and none after.
        expect($status)->toBe('closed')
            ->and($deposits)->toBe(0)
            ->and($logs)->each->toBe('closed');
    } else {
        expect($status)->toBe('active')
            ->and($deposits)->toBeGreaterThan(0)
            ->and($logs)->each->toBe('ok');
    }

    $this->assertLedgerMatchesBalance($wallet->id);
})->with([
    // Either outcome is valid in both; the delays only make each likely.
    'the close most likely first' => [0, 30_000],
    'the deposits most likely first' => [20_000, 0],
]);

it('closes a churning wallet only at zero and stops every movement after it', function (): void {
    $wallet = User::create(['name' => 'Ada'])->wallet();

    $workers = [];

    foreach (range(1, 5) as $i) {
        // Each worker withdraws only what it just deposited, so the balance
        // keeps returning to zero and a withdrawal is never short of money.
        $workers[] = function () use ($wallet): array {
            $instance = Wallet::query()->findOrFail($wallet->id);
            $log = [];

            for ($n = 0; $n < 40; $n++) {
                try {
                    $instance->deposit(1);
                } catch (WalletClosed) {
                    $log[] = 'closed';

                    continue;
                }

                $log[] = 'ok';

                // Never refused: our own deposit keeps the balance above
                // zero, so no close can happen in between.
                $instance->withdraw(1);
            }

            return $log;
        };
    }

    $workers[] = function () use ($wallet): string {
        $instance = Wallet::query()->findOrFail($wallet->id);

        for ($attempt = 1; $attempt <= 2_000; $attempt++) {
            try {
                $instance->close();

                return 'closed';
            } catch (InvalidStatusTransition) {
                usleep(500);
            }
        }

        return 'never closed';
    };

    $results = $this->inParallel($workers);
    $closer = array_pop($results);

    foreach ($results as $log) {
        $firstRefusal = array_search('closed', $log, true);

        if ($firstRefusal !== false) {
            expect(array_slice($log, (int) $firstRefusal))->each->toBe('closed');
        }
    }

    $status = DB::table((string) config('wallet.tables.wallets'))->where('id', $wallet->id)->value('status');

    expect($this->balance($wallet->id))->toBe(0)
        ->and($status)->toBe($closer === 'closed' ? 'closed' : 'active')
        ->and($this->app->make(WalletAuditor::class)->audit()->passed())->toBeTrue();

    $this->assertLedgerMatchesBalance($wallet->id);
});
