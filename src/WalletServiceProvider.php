<?php

declare(strict_types=1);

namespace Anabil\Wallet;

use Anabil\Wallet\Audit\WalletAuditor;
use Anabil\Wallet\Console\CheckWalletsCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wallet.php', 'wallet');

        // Stateless (see WalletManager's docblock), so a singleton is safe
        // under Octane: nothing it resolves is cached on the instance.
        $this->app->singleton(WalletManager::class);

        // Stateless too (see its own docblock): it holds no per-call data,
        // only the connection and table names it reads fresh each audit.
        $this->app->singleton(WalletAuditor::class);
    }

    public function boot(): void
    {
        $this->registerHolderMorphMap();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/wallet.php' => $this->app->configPath('wallet.php'),
            ], 'wallet-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'wallet-migrations');

            $this->commands([
                CheckWalletsCommand::class,
            ]);
        }
    }

    /**
     * Merge the configured holder aliases into Laravel's morph map, leaving
     * aliases the application registered itself untouched.
     */
    private function registerHolderMorphMap(): void
    {
        $holders = config('wallet.holders', []);

        if (is_array($holders) && $holders !== []) {
            /** @var array<string, class-string<Model>> $holders */
            Relation::morphMap(array_diff_key($holders, Relation::morphMap()));
        }
    }
}
