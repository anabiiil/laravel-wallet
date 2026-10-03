<?php

declare(strict_types=1);

use Anabil\Wallet\Audit\IssueCode;
use Anabil\Wallet\Audit\Severity;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The audit proves invariants Eloquent itself guarantees (see Wallet and
 * WalletTransaction): every corruption below is written with DB::table()
 * or forceBalance()/insertTransactionRow(), never through the models, since
 * that is the only way these problems can occur in the first place.
 */

it('passes on a healthy ledger: deposits, withdrawals, a transfer, a credit line, an empty wallet and a 3dp currency', function (): void {
    $user = User::create(['name' => 'Ada']);
    $merchant = Merchant::create(['name' => 'Shop']); // credit line: min_balance -100.00
    $admin = Admin::create(['name' => 'Root']); // USD

    $userWallet = $user->wallet();
    $userWallet->deposit(200);
    $userWallet->withdraw(40);

    $merchantWallet = $merchant->wallet();
    $merchantWallet->deposit(10);
    $userWallet->transferTo($merchantWallet, 25);
    $merchantWallet->withdraw(100); // dips into the credit line

    User::create(['name' => 'Empty'])->wallet(); // zero ledger rows

    $kwd = $user->createWallet('kwd', 'KWD');
    $kwd->deposit('12.345');

    $admin->deposit(5);

    $report = $this->auditor()->audit();

    expect($report->passed())->toBeTrue()
        ->and($report->issues)->toBe([])
        ->and($report->errors())->toBe([])
        ->and($report->warnings())->toBe([])
        ->and($report->walletsChecked)->toBe((int) DB::table($this->walletsTable())->count())
        ->and($report->groupsChecked)->toBe(1)
        ->and($report->toArray())->toBe([
            'wallets_checked' => $report->walletsChecked,
            'groups_checked' => 1,
            'passed' => true,
            'error_count' => 0,
            'warning_count' => 0,
            'issues' => [],
        ]);

    expect(Artisan::call('wallet:check'))->toBe(0)
        ->and(Artisan::output())->toContain('0 error(s), 0 warning(s).');

    Artisan::call('wallet:check', ['--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe($report->toArray());
});

it('reports balance_mismatch alone for an empty wallet whose balance does not match its (zero) ledger sum', function (): void {
    $wallet = User::create(['name' => 'Mia'])->wallet();
    $this->forceBalance($wallet->id, 5_00);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($report->passed())->toBeFalse()
        ->and($codes)->toContain(IssueCode::BalanceMismatch)
        ->and($codes)->not->toContain(IssueCode::LastBalanceAfterMismatch)
        ->and($codes)->not->toContain(IssueCode::BrokenChain)
        ->and($codes)->not->toContain(IssueCode::BelowFloor);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::BalanceMismatch);

    expect($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('5.00 EGP')
        ->and($issue->message)->toContain('0.00 EGP')
        ->and($issue->context)->toBe(['balance' => 5_00, 'sum_amount' => 0, 'difference' => 5_00]);
});

it('reports last_balance_after_mismatch when the newest ledger row disagrees with the stored balance', function (): void {
    $wallet = User::create(['name' => 'Leo'])->wallet();
    $wallet->deposit(10);
    $wallet->deposit(5);

    $last = DB::table($this->transactionsTable())->where('wallet_id', $wallet->id)->orderByDesc('id')->first();

    DB::table($this->transactionsTable())->where('id', $last->id)->update(['balance_after' => 999_00]);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::LastBalanceAfterMismatch)
        ->and($codes)->not->toContain(IssueCode::BalanceMismatch);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::LastBalanceAfterMismatch);

    expect($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('15.00 EGP')
        ->and($issue->message)->toContain('999.00 EGP')
        ->and($issue->context)->toBe(['balance' => 15_00, 'last_balance_after' => 999_00]);

    // Corrupting the newest row's balance_after necessarily also breaks the
    // running-sum chain at that same row: the two codes share a root cause
    // and cannot be fully isolated from each other.
    $chain = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::BrokenChain);

    expect($chain)->not->toBeNull()
        ->and($chain->transactionId)->toBe((int) $last->id);
});

it('reports broken_chain for an earlier row without touching balance_mismatch or last_balance_after_mismatch', function (): void {
    $wallet = User::create(['name' => 'Omar'])->wallet();
    $wallet->deposit(10);
    $wallet->deposit(5);
    $wallet->deposit(2);

    $rows = DB::table($this->transactionsTable())->where('wallet_id', $wallet->id)->orderBy('id')->get();
    $middle = $rows[1];

    DB::table($this->transactionsTable())->where('id', $middle->id)->update(['balance_after' => 777_00]);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::BrokenChain)
        ->and($codes)->not->toContain(IssueCode::BalanceMismatch)
        ->and($codes)->not->toContain(IssueCode::LastBalanceAfterMismatch);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::BrokenChain);

    expect($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBe((int) $middle->id)
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('777.00 EGP')
        ->and($issue->message)->toContain('15.00 EGP')
        ->and($issue->context)->toBe([
            'first_bad_transaction_id' => (int) $middle->id,
            'stored_balance_after' => 777_00,
            'expected_balance_after' => 15_00,
            'bad_count' => 1,
        ]);
});

it('reports below_floor as a warning that still passes, after a credit line is reduced', function (): void {
    $wallet = Merchant::create(['name' => 'Shop'])->wallet(); // min_balance -100.00
    $wallet->withdraw(80); // balance -80.00, within the original floor
    $wallet->setMinBalance(-50); // the floor is raised above the current balance

    $report = $this->auditor()->audit();

    expect($report->passed())->toBeTrue()
        ->and($report->errors())->toBe([])
        ->and($report->warnings())->toHaveCount(1);

    $issue = $report->warnings()[0];

    expect($issue->code)->toBe(IssueCode::BelowFloor)
        ->and($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Warning)
        ->and($issue->message)->toContain('-80.00 EGP')
        ->and($issue->message)->toContain('-50.00 EGP')
        ->and($issue->context)->toBe(['balance' => -80_00, 'min_balance' => -50_00]);

    $exitCode = Artisan::call('wallet:check');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('below_floor')
        ->and($output)->toContain('0 error(s), 1 warning(s).');
});

it('reports invalid_scale for a wallet whose scale column was corrupted', function (): void {
    $wallet = User::create(['name' => 'Nora'])->wallet();
    DB::table($this->walletsTable())->where('id', $wallet->id)->update(['scale' => 5]);

    $report = $this->auditor()->audit();

    expect(collect($report->issues)->pluck('code'))->toContain(IssueCode::InvalidScale);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::InvalidScale);

    expect($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('scale 5')
        ->and($issue->context)->toBe(['scale' => 5]);
});

it('reports unbalanced_group when a transfer group does not sum to zero', function (): void {
    $sender = User::create(['name' => 'Group Sender'])->wallet();
    $sender->setMinBalance(-1_000_00);
    $recipient = User::create(['name' => 'Group Recipient'])->wallet();

    $group = (string) Str::uuid7();

    $this->insertTransactionRow($sender->id, [
        'type' => 'transfer_out',
        'amount' => -10_00,
        'balance_after' => -10_00,
        'group_uuid' => $group,
    ]);
    $this->forceBalance($sender->id, -10_00);

    $this->insertTransactionRow($recipient->id, [
        'type' => 'transfer_in',
        'amount' => 9_00,
        'balance_after' => 9_00,
        'group_uuid' => $group,
    ]);
    $this->forceBalance($recipient->id, 9_00);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::UnbalancedGroup)
        ->and($codes)->not->toContain(IssueCode::MalformedTransfer)
        ->and($codes)->not->toContain(IssueCode::BalanceMismatch)
        ->and($codes)->not->toContain(IssueCode::BelowFloor);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::UnbalancedGroup);

    expect($issue->walletId)->toBeNull()
        ->and($issue->groupUuid)->toBe($group)
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('-1.00 EGP')
        ->and($issue->context)->toBe(['total' => -1_00, 'leg_count' => 2]);
});

it('reports malformed_transfer when a group does not have exactly one transfer_out and one transfer_in', function (): void {
    $w1 = User::create(['name' => 'Malformed 1'])->wallet();
    $w1->setMinBalance(-1_000_00);
    $w2 = User::create(['name' => 'Malformed 2'])->wallet();

    $group = (string) Str::uuid7();

    $this->insertTransactionRow($w1->id, [
        'type' => 'transfer_out',
        'amount' => -5_00,
        'balance_after' => -5_00,
        'group_uuid' => $group,
    ]);
    $this->forceBalance($w1->id, -5_00);

    $this->insertTransactionRow($w2->id, [
        'type' => 'transfer_out',
        'amount' => 5_00,
        'balance_after' => 5_00,
        'group_uuid' => $group,
    ]);
    $this->forceBalance($w2->id, 5_00);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::MalformedTransfer)
        ->and($codes)->not->toContain(IssueCode::UnbalancedGroup);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::MalformedTransfer);

    expect($issue->walletId)->toBeNull()
        ->and($issue->groupUuid)->toBe($group)
        ->and($issue->transactionId)->toBeNull()
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('2 transfer_out')
        ->and($issue->message)->toContain('0 transfer_in')
        ->and($issue->context)->toBe(['out_count' => 2, 'in_count' => 0, 'leg_count' => 2]);
});

it('reports transfer_without_group for a transfer leg with no group_uuid', function (): void {
    $wallet = User::create(['name' => 'Solo'])->wallet();
    $wallet->setMinBalance(-1_000_00);

    $id = $this->insertTransactionRow($wallet->id, [
        'type' => 'transfer_out',
        'amount' => -2_00,
        'balance_after' => -2_00,
        'group_uuid' => null,
    ]);
    $this->forceBalance($wallet->id, -2_00);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::TransferWithoutGroup)
        ->and($codes)->not->toContain(IssueCode::UnbalancedGroup)
        ->and($codes)->not->toContain(IssueCode::MalformedTransfer);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::TransferWithoutGroup);

    expect($issue->walletId)->toBe($wallet->id)
        ->and($issue->groupUuid)->toBeNull()
        ->and($issue->transactionId)->toBe($id)
        ->and($issue->severity())->toBe(Severity::Error)
        ->and($issue->message)->toContain('-2.00 EGP')
        ->and($issue->context)->toBe(['amount' => -2_00, 'type' => 'transfer_out']);
});

it('includes every row of a transfer group in unbalanced_group, not only its transfer legs', function (): void {
    $sender = User::create(['name' => 'Fee Sender'])->wallet();
    $recipient = User::create(['name' => 'Fee Recipient'])->wallet();
    $sender->deposit(100);

    $result = $sender->transferTo($recipient, 30); // balanced on its own: -30.00 / +30.00

    $fee = User::create(['name' => 'Fee Collector'])->wallet();
    $fee->setMinBalance(-1_000_00);

    $this->insertTransactionRow($fee->id, [
        'type' => 'fee',
        'amount' => -5_00,
        'balance_after' => -5_00,
        'group_uuid' => $result->group,
    ]);
    $this->forceBalance($fee->id, -5_00);

    $report = $this->auditor()->audit();
    $codes = collect($report->issues)->pluck('code');

    expect($codes)->toContain(IssueCode::UnbalancedGroup)
        ->and($codes)->not->toContain(IssueCode::MalformedTransfer);

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::UnbalancedGroup);

    expect($issue->groupUuid)->toBe($result->group)
        ->and($issue->context)->toBe(['total' => -5_00, 'leg_count' => 3]);
});

it('scopes the audit to --wallet, excluding issues that sit on other wallets', function (): void {
    $good = User::create(['name' => 'Scoped Good'])->wallet();
    $bad = User::create(['name' => 'Scoped Bad'])->wallet();
    $this->forceBalance($bad->id, 9_99);

    $scoped = $this->auditor()->audit([$good->id]);

    expect($scoped->passed())->toBeTrue()
        ->and($scoped->walletsChecked)->toBe(1)
        ->and($scoped->issues)->toBe([]);

    $other = $this->auditor()->audit([$bad->id]);

    expect($other->passed())->toBeFalse()
        ->and($other->walletsChecked)->toBe(1)
        ->and(collect($other->issues)->pluck('code'))->toContain(IssueCode::BalanceMismatch);
});

it('scopes a transfer group check to groups touching --wallet, even when a leg sits elsewhere', function (): void {
    $sender = User::create(['name' => 'Scope Sender'])->wallet();
    $sender->deposit(50);
    $recipient = User::create(['name' => 'Scope Recipient'])->wallet();
    $bystander = User::create(['name' => 'Scope Bystander'])->wallet();

    $sender->transferTo($recipient, 10);

    $scoped = $this->auditor()->audit([$bystander->id]);

    expect($scoped->passed())->toBeTrue()
        ->and($scoped->groupsChecked)->toBe(0);

    $touching = $this->auditor()->audit([$sender->id]);

    expect($touching->groupsChecked)->toBe(1);
});

it('rejects a non-positive --chunk with exit code 2 and does not run the audit', function (): void {
    expect(Artisan::call('wallet:check', ['--chunk' => '0']))->toBe(2)
        ->and(Artisan::output())->toContain('--chunk');

    expect(Artisan::call('wallet:check', ['--chunk' => 'abc']))->toBe(2);
    expect(Artisan::call('wallet:check', ['--chunk' => '-5']))->toBe(2);
});

it('rejects a non-positive --wallet id with exit code 2', function (): void {
    expect(Artisan::call('wallet:check', ['--wallet' => ['abc']]))->toBe(2)
        ->and(Artisan::output())->toContain('--wallet');

    expect(Artisan::call('wallet:check', ['--wallet' => ['0']]))->toBe(2);
    expect(Artisan::call('wallet:check', ['--wallet' => ['-1']]))->toBe(2);
});

it('checks every wallet exactly once when chunking, including an issue only a later chunk reaches', function (): void {
    $wallets = [];

    for ($i = 0; $i < 6; $i++) {
        $wallets[] = User::create(['name' => "Chunk {$i}"])->wallet();
    }

    $lastWallet = end($wallets);
    $this->forceBalance($lastWallet->id, 123_45);

    $report = $this->auditor()->audit(null, 2);

    expect($report->walletsChecked)->toBe(6)
        ->and($report->passed())->toBeFalse();

    $issue = collect($report->issues)->first(fn ($i): bool => $i->code === IssueCode::BalanceMismatch);

    expect($issue->walletId)->toBe($lastWallet->id);
});

it('runs exactly one wallet-aggregate query and one chain query per chunk, never one per wallet', function (): void {
    for ($i = 0; $i < 4; $i++) {
        User::create(['name' => "Single {$i}"])->wallet()->deposit(10);
    }

    $queries = $this->queriesDuring(fn () => $this->auditor()->audit(null, 2));

    $aggregateQueries = array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'coalesce(agg.sum_amount'),
    );

    $chainQueries = array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'over (partition by'),
    );

    // 4 wallets, chunk 2 => 2 chunks => exactly 2 of each statement, never 4
    // (one per wallet), whatever a concurrent write does between them.
    expect($aggregateQueries)->toHaveCount(2)
        ->and($chainQueries)->toHaveCount(2);
});

it('stays fast and keeps the query count tied to the chunk count, not the row count, on a larger ledger', function (): void {
    $holder = User::create(['name' => 'Bulk Holder']);

    $walletRows = [];

    for ($i = 0; $i < 200; $i++) {
        $walletRows[] = [
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->id,
            'name' => "bulk-{$i}",
            'currency' => 'EGP',
            'scale' => 2,
            'balance' => 250_00,
            'min_balance' => 0,
            'status' => 'active',
        ];
    }

    foreach (array_chunk($walletRows, 100) as $chunk) {
        DB::table($this->walletsTable())->insert($chunk);
    }

    $walletIds = DB::table($this->walletsTable())
        ->where('name', 'like', 'bulk-%')
        ->orderBy('id')
        ->pluck('id');

    $transactionRows = [];

    foreach ($walletIds as $walletId) {
        foreach ([['deposit', 100_00, 100_00], ['deposit', 200_00, 300_00], ['withdraw', -50_00, 250_00]] as [$type, $amount, $balanceAfter]) {
            $transactionRows[] = [
                'wallet_id' => $walletId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ];
        }
    }

    foreach (array_chunk($transactionRows, 150) as $chunk) {
        DB::table($this->transactionsTable())->insert($chunk);
    }

    $report = null;
    $start = microtime(true);

    $queries = $this->queriesDuring(function () use (&$report): void {
        $report = $this->auditor()->audit(null, 50);
    });

    $elapsed = microtime(true) - $start;

    expect($report->passed())->toBeTrue()
        ->and($report->walletsChecked)->toBe(200)
        ->and($elapsed)->toBeLessThan(5.0);

    $aggregateQueries = array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'coalesce(agg.sum_amount'),
    );

    // 200 wallets / chunk 50 = 4 chunks, regardless of the 600 ledger rows.
    expect($aggregateQueries)->toHaveCount(4);
});
