<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests;

use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\Fingerprint;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\WalletServiceProvider;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Testbench boots a minimal Laravel app in memory so the package can be
 * tested without a host application.
 */
abstract class TestCase extends Orchestra
{
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
        $app['config']->set('wallet.holders', [
            'user' => User::class,
            'merchant' => Merchant::class,
            'admin' => Admin::class,
        ]);

        // Transaction references are stored with a morph alias too.
        Relation::morphMap(['order' => Order::class]);

        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'wallet'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'wallet'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
        ]);
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
     * Inserts a ledger row directly, the way the future ledger writer will.
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
     * change plus ledger row with the key), bypassing the package. The
     * fingerprint is computed for $delta / $details unless $hash is given.
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
