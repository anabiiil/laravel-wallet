<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Concurrency;

use Anabil\Wallet\Tests\Concerns\ConfiguresWalletEnvironment;
use Anabil\Wallet\WalletServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Throwable;

/**
 * Base class for tests that run real concurrent database work in forked
 * child processes (pcntl_fork).
 *
 * Children only see committed data, so there is no RefreshDatabase
 * wrapping transaction: the schema is migrated fresh for every test and
 * the data is committed for real. On SQLite the database is a temporary
 * FILE (an in-memory database cannot be shared between processes) in WAL
 * mode with a busy timeout, so writers queue behind each other instead of
 * failing with "database is locked". MySQL and PostgreSQL use the
 * DB_CONNECTION connection as it is.
 *
 * Fork hygiene: the parent drops its connections before forking, so no
 * process ever inherits a live PDO (a child closing an inherited MySQL or
 * PostgreSQL handle would end the parent's session on the shared socket).
 * Each child opens its own connection, reports its result through a temp
 * file and leaves with pcntl_exec() so that none of the inherited PHP
 * shutdown handlers (Pest's kernel, PHPUnit's output) run in the child.
 */
abstract class ConcurrencyTestCase extends Orchestra
{
    use ConfiguresWalletEnvironment;

    /**
     * How long the parent waits for its children before killing them and
     * failing the test (a hung child most likely means a deadlock).
     */
    protected const CHILD_TIMEOUT_SECONDS = 60;

    private ?string $sqlitePath = null;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrency tests fork child processes and need the pcntl extension (pcntl_fork is unavailable).');
        }

        parent::setUp();

        $this->migrateFresh();
    }

    protected function tearDown(): void
    {
        // Leave an empty, migrated schema behind on server databases, so the
        // other suites find the database as they expect it.
        if ($this->sqlitePath === null && isset($this->app)) {
            $this->migrateFresh();
        }

        DB::purge();

        parent::tearDown();

        if ($this->sqlitePath !== null) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                if (is_file($this->sqlitePath.$suffix)) {
                    unlink($this->sqlitePath.$suffix);
                }
            }

            $this->sqlitePath = null;
        }
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WalletServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->configureWalletEnvironment($app);

        if ($app['config']->get('database.default') === 'sqlite') {
            $this->sqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wallet-concurrency-'.getmypid().'-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->sqlitePath);

            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => $this->sqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 10_000,
                'journal_mode' => 'wal',
                'synchronous' => 'normal',
            ]);
        }
    }

    protected function balance(int $walletId): int
    {
        return (int) DB::table((string) config('wallet.tables.wallets', 'wallets'))->where('id', $walletId)->value('balance');
    }

    /**
     * The wallet's ledger rows in id order.
     *
     * @return list<array<string, mixed>>
     */
    protected function ledger(int $walletId): array
    {
        return DB::table((string) config('wallet.tables.transactions', 'wallet_transactions'))
            ->where('wallet_id', $walletId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * The ledger sums to the balance and, in id order, every balance_after
     * is the running total.
     */
    protected function assertLedgerMatchesBalance(int $walletId): void
    {
        $running = 0;

        foreach ($this->ledger($walletId) as $row) {
            $running += (int) $row['amount'];

            $this->assertSame($running, (int) $row['balance_after'], sprintf('balance_after chain broken at row %s of wallet %d', (string) $row['id'], $walletId));
        }

        $this->assertSame($this->balance($walletId), $running, sprintf('ledger sum differs from the balance of wallet %d', $walletId));
    }

    private function migrateFresh(): void
    {
        $this->app->make(Kernel::class)->call('migrate:fresh', [
            '--path' => [
                realpath(__DIR__.'/../../database/migrations'),
                realpath(__DIR__.'/../Fixtures/migrations'),
            ],
            '--realpath' => true,
        ]);
    }

    /**
     * Runs every worker in its own child process, all starting at the same
     * moment, and returns what each returned, in order. A worker that
     * throws makes its child exit with a non-zero status, which fails the
     * test with the exception's message.
     *
     * @param  list<Closure(int): mixed>  $workers  each receives its index
     * @return list<mixed>
     */
    protected function inParallel(array $workers): array
    {
        // Never fork with a live connection: see the class docblock.
        DB::purge();

        $startAt = microtime(true) + 0.25 + 0.02 * count($workers);
        $children = [];

        foreach ($workers as $index => $worker) {
            $file = (string) tempnam(sys_get_temp_dir(), 'wallet-child-');
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                $this->runChild($worker, $index, $file, $startAt);
            }

            $children[$index] = ['pid' => $pid, 'file' => $file, 'status' => null];
        }

        $this->waitFor($children);

        $results = [];
        $failures = [];

        foreach ($children as $index => $child) {
            $payload = is_file($child['file']) ? unserialize((string) file_get_contents($child['file'])) : null;

            if (is_file($child['file'])) {
                unlink($child['file']);
            }

            $status = $child['status'];
            $exitCode = $status !== null && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null;

            if ($exitCode !== 0 || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
                $failures[] = sprintf(
                    'child #%d (pid %d) exit=%s: %s',
                    $index,
                    $child['pid'],
                    $exitCode === null ? 'signal/timeout' : (string) $exitCode,
                    is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : 'no result written',
                );

                continue;
            }

            $results[$index] = $payload['result'];
        }

        if ($failures !== []) {
            $this->fail("Child processes failed:\n".implode("\n", $failures));
        }

        return array_values($results);
    }

    /**
     * @param  Closure(int): mixed  $worker
     */
    private function runChild(Closure $worker, int $index, string $file, float $startAt): never
    {
        $code = 1;

        try {
            // A fresh connection of our own, opened before the start line so
            // connecting does not spread the children out.
            DB::purge();
            DB::connection()->getPdo();

            $wait = $startAt - microtime(true);

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            $result = $worker($index);

            file_put_contents($file, serialize(['ok' => true, 'result' => $result]));
            $code = 0;
        } catch (Throwable $e) {
            file_put_contents($file, serialize([
                'ok' => false,
                'error' => $e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(),
            ]));
        }

        try {
            DB::disconnect();
        } catch (Throwable) {
        }

        // Replace the process image: exits with $code without running the
        // PHP shutdown functions inherited from the test runner.
        pcntl_exec('/bin/sh', ['-c', 'exit '.$code]);

        // Only reached if exec failed.
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($code);
    }

    /**
     * @param  array<int, array{pid: int, file: string, status: int|null}>  $children
     */
    private function waitFor(array &$children): void
    {
        $deadline = microtime(true) + static::CHILD_TIMEOUT_SECONDS;
        $pending = count($children);

        while ($pending > 0) {
            foreach ($children as $index => $child) {
                if ($child['status'] !== null) {
                    continue;
                }

                $status = 0;

                if (pcntl_waitpid($child['pid'], $status, WNOHANG) === $child['pid']) {
                    $children[$index]['status'] = $status;
                    $pending--;
                }
            }

            if ($pending === 0) {
                break;
            }

            if (microtime(true) > $deadline) {
                foreach ($children as $child) {
                    if ($child['status'] === null) {
                        function_exists('posix_kill') ? posix_kill($child['pid'], SIGKILL) : null;
                        pcntl_waitpid($child['pid'], $status);
                    }
                }

                $this->fail(sprintf('Child processes did not finish within %d seconds (deadlock?).', static::CHILD_TIMEOUT_SECONDS));
            }

            usleep(5_000);
        }
    }
}
