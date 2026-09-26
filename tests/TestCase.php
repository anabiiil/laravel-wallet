<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests;

use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Anabil\Wallet\WalletServiceProvider;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
}
