<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Concerns;

use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\Guest;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The package configuration and database connections shared by every test
 * case. The connection is chosen with the DB_CONNECTION environment variable
 * (sqlite by default; mysql and pgsql in CI).
 */
trait ConfiguresWalletEnvironment
{
    protected function configureWalletEnvironment($app): void
    {
        $app['config']->set('wallet.holders', [
            'user' => User::class,
            'merchant' => Merchant::class,
            'admin' => Admin::class,
        ]);

        // WalletManager (Wallets::holder()) checks these guards, in order,
        // to find who is currently authenticated.
        $app['config']->set('wallet.guards', ['web', 'merchant', 'admin']);

        $app['config']->set('auth.providers.users.model', User::class);

        // Guards behind which the holder fixtures (and the non-holder Guest
        // fixture) can authenticate, each with its own Eloquent provider.
        foreach ([
            'merchant' => Merchant::class,
            'admin' => Admin::class,
            'guest' => Guest::class,
        ] as $guard => $model) {
            $app['config']->set("auth.guards.{$guard}", ['driver' => 'session', 'provider' => $guard.'s']);
            $app['config']->set("auth.providers.{$guard}s", ['driver' => 'eloquent', 'model' => $model]);
        }

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
}
