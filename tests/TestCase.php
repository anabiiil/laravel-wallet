<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests;

use Anabil\Wallet\Audit\WalletAuditor;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\Fingerprint;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Concerns\ConfiguresWalletEnvironment;
use Anabil\Wallet\WalletServiceProvider;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Testbench boots a minimal Laravel app in memory so the package can be
 * tested without a host application.
 */
abstract class TestCase extends Orchestra
{
    use ConfiguresWalletEnvironment;
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WalletServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Holder tables for the fixture models (users, merchants, admins).
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $this->configureWalletEnvironment($app);
    }

    protected function walletsTable(): string
    {
        return config('wallet.tables.wallets', 'wallets');
    }

    protected function transactionsTable(): string
    {
        return config('wallet.tables.transactions', 'wallet_transactions');
    }

    /**
     * The raw wallet row, read without going through the models.
     *
     * @return array<string, mixed>
     */
    protected function walletRow(int $id): array
    {
        return (array) DB::table($this->walletsTable())->where('id', $id)->first();
    }

    /**
     * Every raw ledger row, read without going through the models.
     *
     * @return list<array<string, mixed>>
     */
    protected function transactionRows(): array
    {
        return DB::table($this->transactionsTable())->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->values()->all();
    }

    /**
     * Inserts a ledger row directly (amounts in minor units, as stored).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function insertTransactionRow(int $walletId, array $overrides = []): int
    {
        return (int) DB::table($this->transactionsTable())->insertGetId(array_merge([
            'wallet_id' => $walletId,
            'type' => 'deposit',
            'amount' => 100,
            'balance_after' => 100,
        ], $overrides));
    }

    /**
     * Attributes for a forged ledger entry, used to prove Eloquent refuses it.
     *
     * @return array<string, mixed>
     */
    public static function ledgerAttributes(int $walletId): array
    {
        return ['wallet_id' => $walletId, 'type' => 'deposit', 'amount' => 999, 'balance_after' => 999];
    }

    /**
     * Runs the callback with Model::shouldBeStrict() on, restoring the
     * defaults afterwards even when it throws.
     */
    protected function withStrictModels(Closure $callback): mixed
    {
        Model::shouldBeStrict();

        try {
            return $callback();
        } finally {
            Model::shouldBeStrict(false);
        }
    }

    /**
     * Overwrites a wallet's balance directly, bypassing the ledger. Only for
     * setting up edge cases (e.g. a balance near the integer limit).
     */
    protected function forceBalance(int $walletId, int $balance): void
    {
        DB::table($this->walletsTable())->where('id', $walletId)->update(['balance' => $balance]);
    }

    /**
     * The sum of a wallet's ledger amounts, read without the models.
     */
    protected function ledgerSum(int $walletId): int
    {
        return (int) DB::table($this->transactionsTable())->where('wallet_id', $walletId)->sum('amount');
    }

    /**
     * The bound WalletAuditor, resolved from the container like application
     * code would (it is registered as a singleton by WalletServiceProvider).
     */
    protected function auditor(): WalletAuditor
    {
        return $this->app->make(WalletAuditor::class);
    }

    /**
     * Simulates a concurrent request winning an idempotency race: $competitor
     * runs once, right after the first query that looks up an idempotency
     * key, i.e. between our lookup and our write.
     */
    protected function afterIdempotencyLookup(Closure $competitor): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $competitor): void {
            if (! $fired
                && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'idempotency_key')) {
                $fired = true;
                $competitor();
            }
        });
    }

    /**
     * Records an entry the way a competing request would have (balance
     * change plus ledger row with the key), bypassing the package. $delta is
     * in minor units, as stored. The fingerprint is computed for $delta /
     * $details unless $hash is given.
     */
    protected function competingEntry(Wallet $wallet, int $delta, Details $details, string $key, ?string $hash = null): int
    {
        DB::table($this->walletsTable())->where('id', $wallet->id)->increment('balance', $delta);

        return $this->insertTransactionRow($wallet->id, [
            'type' => $details->type,
            'amount' => $delta,
            'balance_after' => (int) DB::table($this->walletsTable())->where('id', $wallet->id)->value('balance'),
            'idempotency_key' => $key,
            'idempotency_hash' => $hash ?? Fingerprint::for($delta, $details),
        ]);
    }

    /**
     * The fingerprint a transfer stores on its sender entry ($amount in
     * minor units).
     *
     * @param  array<array-key, mixed>  $meta
     */
    protected function transferFingerprint(
        Wallet $recipient,
        int $amount,
        ?Model $reference = null,
        ?string $description = null,
        ?string $recipientDescription = null,
        array $meta = [],
        ?Model $performedBy = null,
    ): string {
        return Fingerprint::for(
            -$amount,
            Details::internal(CoreType::TransferOut, $reference, $description, $meta, $performedBy),
            [
                'operation' => 'transfer',
                'recipient_wallet' => (string) $recipient->id,
                'recipient_description' => $recipientDescription ?? $description,
            ],
        );
    }

    /**
     * Records a complete transfer the way a competing request would have
     * (both balance changes plus both ledger rows, the key on the sender's),
     * bypassing the package ($amount in minor units). Returns the ids of the
     * sender and recipient rows.
     *
     * @return array{int, int}
     */
    protected function competingTransfer(Wallet $sender, Wallet $recipient, int $amount, string $key, ?string $hash = null): array
    {
        $group = (string) Str::uuid7();

        DB::table($this->walletsTable())->where('id', $sender->id)->decrement('balance', $amount);
        DB::table($this->walletsTable())->where('id', $recipient->id)->increment('balance', $amount);

        $out = $this->insertTransactionRow($sender->id, [
            'type' => 'transfer_out',
            'amount' => -$amount,
            'balance_after' => (int) DB::table($this->walletsTable())->where('id', $sender->id)->value('balance'),
            'idempotency_key' => $key,
            'idempotency_hash' => $hash ?? $this->transferFingerprint($recipient, $amount),
            'group_uuid' => $group,
        ]);

        $in = $this->insertTransactionRow($recipient->id, [
            'type' => 'transfer_in',
            'amount' => $amount,
            'balance_after' => (int) DB::table($this->walletsTable())->where('id', $recipient->id)->value('balance'),
            'group_uuid' => $group,
        ]);

        return [$out, $in];
    }

    /**
     * Simulates a concurrent reversal winning the race: $competitor runs
     * once, right after the first query that looks for an existing
     * reversal (reverses_id), i.e. between our check and our write.
     */
    protected function afterReversalCheck(Closure $competitor): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $competitor): void {
            if (! $fired
                && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'reverses_id')) {
                $fired = true;
                $competitor();
            }
        });
    }

    /**
     * The fingerprint a reversal stores under its idempotency key. $original
     * is the reversed entry (for a transfer: its transfer_out leg).
     *
     * @param  array<array-key, mixed>  $meta
     */
    protected function reversalFingerprint(
        WalletTransaction $original,
        ?string $description = null,
        array $meta = [],
        ?Model $performedBy = null,
        bool $force = false,
    ): string {
        return Fingerprint::for(
            -$original->amountInMinor(),
            Details::internal(CoreType::Reversal, null, $description, $meta, $performedBy),
            ['operation' => 'reversal', 'reverses' => (string) $original->getKey(), 'force' => $force],
        );
    }

    /**
     * Records the reversal of $original the way a competing request would
     * have (balance change plus linked ledger row), bypassing the package.
     * Returns the new row's id.
     */
    protected function competingReversal(WalletTransaction $original, ?string $key = null, ?string $hash = null, ?string $group = null): int
    {
        $walletId = (int) $original->getRawOriginal('wallet_id');
        $delta = -$original->amountInMinor();

        DB::table($this->walletsTable())->where('id', $walletId)->increment('balance', $delta);

        return $this->insertTransactionRow($walletId, [
            'type' => 'reversal',
            'amount' => $delta,
            'balance_after' => (int) DB::table($this->walletsTable())->where('id', $walletId)->value('balance'),
            'reference_type' => $original->getRawOriginal('reference_type'),
            'reference_id' => $original->getRawOriginal('reference_id'),
            'idempotency_key' => $key,
            'idempotency_hash' => $key === null ? null : ($hash ?? $this->reversalFingerprint($original)),
            'group_uuid' => $group,
            'reverses_id' => $original->getKey(),
        ]);
    }

    /**
     * Runs the callback and returns the SQL of every query it executed.
     *
     * @return list<string>
     */
    protected function queriesDuring(Closure $callback): array
    {
        $queries = [];
        $listening = true;

        DB::listen(function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening) {
                $queries[] = $query->sql;
            }
        });

        try {
            $callback();
        } finally {
            $listening = false;
        }

        return $queries;
    }
}
