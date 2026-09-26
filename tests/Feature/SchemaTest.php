<?php

declare(strict_types=1);

use Anabil\Wallet\Support\MorphKey;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * These tests only touch Schema/DB::table — the package has no models yet —
 * and are written to be portable across sqlite (used locally) and the
 * mysql/pgsql connections exercised in CI.
 */
function walletsTable(): string
{
    return config('wallet.tables.wallets', 'wallets');
}

function transactionsTable(): string
{
    return config('wallet.tables.transactions', 'wallet_transactions');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walletAttributes(array $overrides = []): array
{
    return array_merge([
        'holder_type' => 'App\\Models\\User',
        'holder_id' => 1,
        'name' => 'default',
        'currency' => 'EGP',
        'balance' => 0,
        'min_balance' => 0,
        'status' => 'active',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertWallet(array $overrides = []): int
{
    return (int) DB::table(walletsTable())->insertGetId(walletAttributes($overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transactionAttributes(int $walletId, array $overrides = []): array
{
    return array_merge([
        'wallet_id' => $walletId,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
    ], $overrides);
}

/**
 * @return array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}|null
 */
function indexByName(string $table, string $name): ?array
{
    return collect(Schema::getIndexes($table))->firstWhere('name', $name);
}

/**
 * Builds a throwaway table with a morph pair for the given key type, so the
 * key types can be checked without rebuilding the real wallet tables.
 */
function morphProbe(string $type): string
{
    config(['wallet.morph_keys.holder' => $type]);

    Schema::create('morph_key_probe', function (Blueprint $table): void {
        $table->id();
        MorphKey::columns($table, 'holder', 'holder', false);
    });

    return 'morph_key_probe';
}

it('has exactly the expected wallets columns', function (): void {
    $expected = [
        'id', 'holder_type', 'holder_id', 'name', 'currency', 'balance',
        'min_balance', 'status', 'meta', 'created_at', 'updated_at',
    ];

    expect(collect(Schema::getColumnListing(walletsTable()))->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
});

it('has exactly the expected wallet_transactions columns', function (): void {
    $expected = [
        'id', 'wallet_id', 'type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'performed_by_type', 'performed_by_id',
        'description', 'meta', 'idempotency_key', 'idempotency_hash',
        'group_uuid', 'reverses_id', 'created_at',
    ];

    expect(collect(Schema::getColumnListing(transactionsTable()))->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
});

it('has a unique index on holder_type, holder_id, name for wallets', function (): void {
    $expectedColumns = collect(['holder_type', 'holder_id', 'name'])->sort()->values()->all();

    $index = collect(Schema::getIndexes(walletsTable()))
        ->first(fn (array $index): bool => $index['unique']
            && collect($index['columns'])->sort()->values()->all() === $expectedColumns);

    expect($index)->not->toBeNull();
});

it('has the expected named indexes on wallet_transactions', function (string $suffix, bool $unique): void {
    $table = transactionsTable();

    $index = indexByName($table, "{$table}_{$suffix}");

    expect($index)->not->toBeNull()
        ->and($index['unique'])->toBe($unique);
})->with([
    ['wallet_id_id_index', false],
    ['reference_index', false],
    ['performed_by_index', false],
    ['idempotency_unique', true],
    ['group_uuid_index', false],
    ['reverses_id_unique', true],
]);

it('prevents duplicate wallets for the same holder and name', function (): void {
    insertWallet(['name' => 'main']);

    expect(fn () => insertWallet(['name' => 'main']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same holder to have wallets with different names', function (): void {
    insertWallet(['name' => 'main']);
    insertWallet(['name' => 'savings']);

    expect(DB::table(walletsTable())->count())->toBe(2);
});

it('allows multiple transactions with a null idempotency key on the same wallet', function (): void {
    $walletId = insertWallet();

    DB::table(transactionsTable())->insert(transactionAttributes($walletId));
    DB::table(transactionsTable())->insert(transactionAttributes($walletId));

    expect(DB::table(transactionsTable())->where('wallet_id', $walletId)->count())->toBe(2);
});

it('rejects duplicate idempotency keys on the same wallet', function (): void {
    $walletId = insertWallet();

    DB::table(transactionsTable())->insert(transactionAttributes($walletId, ['idempotency_key' => 'abc']));

    expect(fn () => DB::table(transactionsTable())->insert(
        transactionAttributes($walletId, ['idempotency_key' => 'abc'])
    ))->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same idempotency key across different wallets', function (): void {
    $walletA = insertWallet(['name' => 'a']);
    $walletB = insertWallet(['name' => 'b']);

    DB::table(transactionsTable())->insert(transactionAttributes($walletA, ['idempotency_key' => 'shared']));
    DB::table(transactionsTable())->insert(transactionAttributes($walletB, ['idempotency_key' => 'shared']));

    expect(DB::table(transactionsTable())->where('idempotency_key', 'shared')->count())->toBe(2);
});

it('allows multiple transactions with a null reverses_id', function (): void {
    $walletId = insertWallet();

    DB::table(transactionsTable())->insert(transactionAttributes($walletId));
    DB::table(transactionsTable())->insert(transactionAttributes($walletId));

    expect(DB::table(transactionsTable())->count())->toBe(2);
});

it('rejects two transactions reversing the same transaction', function (): void {
    $walletId = insertWallet();

    $originalId = DB::table(transactionsTable())->insertGetId(transactionAttributes($walletId));

    DB::table(transactionsTable())->insert(transactionAttributes($walletId, ['reverses_id' => $originalId]));

    expect(fn () => DB::table(transactionsTable())->insert(
        transactionAttributes($walletId, ['reverses_id' => $originalId])
    ))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects a transaction referencing a non-existent wallet', function (): void {
    expect(fn () => DB::table(transactionsTable())->insert(transactionAttributes(999_999)))
        ->toThrow(QueryException::class);
});

it('sets created_at automatically when omitted on insert', function (): void {
    $walletId = insertWallet();

    DB::table(transactionsTable())->insert(transactionAttributes($walletId));

    $row = DB::table(transactionsTable())->where('wallet_id', $walletId)->first();

    expect($row->created_at)->not->toBeNull();
});

it('stores the holder id with the configured key type', function (string $type, Closure $key): void {
    $table = morphProbe($type);
    $id = (string) $key();

    DB::table($table)->insert(['holder_type' => 'user', 'holder_id' => $id]);

    expect(DB::table($table)->value('holder_id'))->toBe($id);
})->with([
    'uuid' => ['uuid', fn () => Str::uuid()],
    'ulid' => ['ulid', fn () => Str::ulid()],
]);

it('throws for an invalid morph key type', function (): void {
    config(['wallet.morph_keys.holder' => 'bigint']);

    expect(fn () => Schema::create('morph_key_probe', function (Blueprint $table): void {
        MorphKey::columns($table, 'holder', 'holder', false);
    }))->toThrow(InvalidArgumentException::class);
});
