<?php

declare(strict_types=1);

use Anabil\Wallet\Exceptions\IdempotencyConflict;
use Anabil\Wallet\Exceptions\ImmutableWallet;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidAmount;
use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Anabil\Wallet\Exceptions\InvalidTransfer;
use Anabil\Wallet\Exceptions\InvalidWalletAttribute;
use Anabil\Wallet\Exceptions\WalletException;
use Anabil\Wallet\Ledger\Details;
use Anabil\Wallet\Ledger\Fingerprint;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Tests\Fixtures\Merchant;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

/*
 * The public API takes amounts in major units (int, float or decimal
 * string) and reads them back as decimal strings with the wallet's scale;
 * the database keeps integers in minor units.
 */

beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
    $this->wallet = $this->user->wallet();
});

it('takes major units and reads decimal strings', function (): void {
    $tx = $this->wallet->deposit(250);

    expect($this->wallet->balance)->toBe('250.00')
        ->and($this->wallet->formatted())->toBe('250.00 EGP')
        ->and($this->wallet->balanceInMinor())->toBe(250_00)
        ->and($tx->amount)->toBe('250.00')
        ->and($tx->balance_after)->toBe('250.00')
        ->and((int) $this->walletRow($this->wallet->id)['balance'])->toBe(250_00)
        ->and((int) $this->transactionRows()[0]['amount'])->toBe(250_00);
});

it('treats 100.50, "100.50" and 100.5 as the same amount', function (): void {
    $entries = [
        $this->wallet->deposit(100.50),
        $this->wallet->deposit('100.50'),
        $this->wallet->deposit(100.5),
        $this->wallet->deposit('100.500'),
    ];

    expect(array_map(fn (WalletTransaction $tx): string => $tx->amount, $entries))->toBe(['100.50', '100.50', '100.50', '100.50'])
        ->and(array_map(fn (array $row): int => (int) $row['amount'], $this->transactionRows()))->toBe([100_50, 100_50, 100_50, 100_50])
        ->and($this->wallet->balance)->toBe('402.00');
});

it('converts common float amounts exactly', function (): void {
    $this->wallet->deposit(19.99);
    $this->wallet->deposit('0.07');
    $this->wallet->deposit(1234567.89);

    expect(array_map(fn (array $row): int => (int) $row['amount'], $this->transactionRows()))->toBe([19_99, 7, 1234567_89])
        ->and($this->wallet->balance)->toBe('1234587.95');
});

it('refuses more decimals than the wallet has, but not on a 3-decimal wallet', function (): void {
    expect(fn () => $this->wallet->deposit(10.005))->toThrow(InvalidAmount::class, 'uses 2 decimal places')
        ->and(fn () => $this->wallet->deposit('10.005'))->toThrow(InvalidAmount::class, 'never rounded')
        ->and(fn () => $this->wallet->deposit(0.001))->toThrow(InvalidAmount::class, 'never rounded')
        ->and($this->transactionRows())->toBe([]);

    $kwd = $this->user->createWallet('kwd', 'KWD');
    $tx = $kwd->deposit(10.005);
    $kwd->deposit('0.12');

    expect($kwd->scale())->toBe(3)
        ->and($tx->amount)->toBe('10.005')
        ->and($tx->amountInMinor())->toBe(10_005)
        ->and($kwd->balance)->toBe('10.125')
        ->and($kwd->formatted())->toBe('10.125 KWD')
        ->and($kwd->min_balance)->toBe('0.000')
        ->and((int) $this->walletRow($kwd->id)['balance'])->toBe(10_125)
        ->and(fn () => $kwd->deposit('1.0001'))->toThrow(InvalidAmount::class, 'uses 3 decimal places');
});

it('refuses 0.1 + 0.2 rather than rounding it', function (): void {
    expect(fn () => $this->wallet->deposit(0.1 + 0.2))->toThrow(InvalidAmount::class, '0.30000000000000004')
        ->and($this->transactionRows())->toBe([]);
});

it('refuses malformed strings without writing', function (string $amount): void {
    expect(fn () => $this->wallet->deposit($amount))->toThrow(InvalidAmount::class)
        ->and(fn () => $this->wallet->withdraw($amount))->toThrow(InvalidAmount::class)
        ->and($this->transactionRows())->toBe([]);
})->with([
    'thousands separator' => ['1,000'],
    'leading space' => [' 10'],
    'exponent' => ['1e3'],
    'plus sign' => ['+5'],
    'leading dot' => ['.5'],
    'trailing dot' => ['5.'],
    'letters' => ['abc'],
    'empty' => [''],
]);

it('refuses NAN and INF', function (float $amount): void {
    expect(fn () => $this->wallet->deposit($amount))->toThrow(InvalidAmount::class, 'NAN and INF');
})->with([NAN, INF, -INF]);

it('refuses a float too large to be exact and suggests a string', function (): void {
    expect(fn () => $this->wallet->deposit(1.0E+15))->toThrow(InvalidAmount::class, 'Pass the amount as a string');

    $this->wallet->deposit('1000000000000000.00');

    expect($this->wallet->balance)->toBe('1000000000000000.00');
});

it('refuses amounts beyond the 64-bit range', function (): void {
    expect(fn () => $this->wallet->deposit('92233720368547758.08'))->toThrow(InvalidAmount::class, 'too large')
        ->and(fn () => $this->wallet->deposit(PHP_INT_MAX))->toThrow(InvalidAmount::class, 'too large')
        ->and($this->transactionRows())->toBe([]);
});

it('refuses amounts that are zero or negative once converted', function (int|float|string $amount, string $shown): void {
    expect(fn () => $this->wallet->deposit($amount))->toThrow(InvalidAmount::class, "[{$shown}]");
})->with([
    [0, '0.00'],
    ['0.00', '0.00'],
    [-0.0, '0.00'],
    ['-0.01', '-0.01'],
    [-5, '-5.00'],
]);

it('transfers a decimal amount', function (): void {
    $merchant = Merchant::create(['name' => 'Shop'])->wallet();
    $this->wallet->deposit(100);

    $result = $this->wallet->transferTo($merchant, 99.99);

    expect($result->amount())->toBe('99.99')
        ->and($result->out->amount)->toBe('-99.99')
        ->and($result->in->amount)->toBe('99.99')
        ->and($this->wallet->balance)->toBe('0.01')
        ->and($merchant->balance)->toBe('99.99')
        ->and(array_map(fn (array $row): int => (int) $row['amount'], $this->transactionRows()))->toBe([100_00, -99_99, 99_99]);
});

it('transfers between 3-decimal wallets', function (): void {
    $a = $this->user->createWallet('a', 'KWD');
    $b = $this->user->createWallet('b', 'KWD');
    $a->deposit('5.250');

    $result = $a->transferTo($b, '1.005');

    expect($result->amount())->toBe('1.005')
        ->and($a->balance)->toBe('4.245')
        ->and($b->balance)->toBe('1.005')
        ->and(fn () => $a->transferTo($b, '0.0001'))->toThrow(InvalidAmount::class);
});

it('refuses a transfer between wallets of different scales', function (): void {
    // Both EGP, but the second was created after EGP's scale was changed.
    config(['wallet.currency_scales.EGP' => 3]);
    $other = User::create(['name' => 'Bob'])->wallet();
    $this->wallet->deposit(10);

    expect($this->wallet->scale())->toBe(2)
        ->and($other->scale())->toBe(3)
        ->and(fn () => $this->wallet->transferTo($other, 1))->toThrow(InvalidTransfer::class, 'same scale')
        ->and(fn () => $other->transferTo($this->wallet, 1))->toThrow(InvalidTransfer::class, 'same scale')
        ->and($this->transactionRows())->toHaveCount(1);
});

it('sets a negative minimum balance in major units and lets it be spent', function (): void {
    $this->wallet->setMinBalance(-500);

    expect($this->wallet->min_balance)->toBe('-500.00')
        ->and((int) $this->walletRow($this->wallet->id)['min_balance'])->toBe(-500_00)
        ->and($this->wallet->spendable())->toBe('500.00');

    $this->wallet->withdraw('499.99');

    expect($this->wallet->balance)->toBe('-499.99')
        ->and($this->wallet->spendable())->toBe('0.01')
        ->and($this->wallet->canWithdraw('0.01'))->toBeTrue()
        ->and($this->wallet->canWithdraw('0.02'))->toBeFalse()
        ->and(fn () => $this->wallet->withdraw('0.02'))->toThrow(InsufficientFunds::class);

    $this->wallet->withdraw(0.01);

    expect($this->wallet->balance)->toBe('-500.00');
});

it('writes min_balance in major units through the attribute too', function (): void {
    $this->wallet->min_balance = '-5.5';
    $this->wallet->save();

    expect($this->wallet->min_balance)->toBe('-5.50')
        ->and($this->wallet->minBalanceInMinor())->toBe(-5_50)
        ->and((int) $this->walletRow($this->wallet->id)['min_balance'])->toBe(-5_50)
        ->and(fn () => $this->wallet->setMinBalance('-1.001'))->toThrow(InvalidAmount::class)
        ->and(fn () => $this->wallet->forceFill(['min_balance' => ['x']]))->toThrow(InvalidAmount::class, 'of type [array]');
});

it('refuses model-level increments of min_balance', function (): void {
    expect(fn () => $this->wallet->increment('min_balance', 100))->toThrow(WalletException::class, 'setMinBalance()')
        ->and($this->wallet->min_balance)->toBe('0.00')
        ->and((int) $this->walletRow($this->wallet->id)['min_balance'])->toBe(0);
});

it('creates a wallet with a decimal minimum balance', function (): void {
    $wallet = $this->user->createWallet('credit', minBalance: '-250.5');

    expect($wallet->min_balance)->toBe('-250.50')
        ->and((int) $this->walletRow($wallet->id)['min_balance'])->toBe(-250_50)
        ->and(fn () => $this->user->createWallet('bad', minBalance: '-1.001'))->toThrow(InvalidAmount::class)
        ->and($this->user->hasWallet('bad'))->toBeFalse();
});

it('reads the minimum balance a holder gives in major units', function (): void {
    $wallet = Merchant::create(['name' => 'Shop'])->wallet();

    expect($wallet->min_balance)->toBe('-100.00')
        ->and($wallet->minBalanceInMinor())->toBe(-100_00);
});

it('reports zero with the right scale for a wallet that does not exist', function (): void {
    $bob = User::create(['name' => 'Bob']);

    expect($bob->balance())->toBe('0.00')
        ->and($bob->hasWallet())->toBeFalse();

    config(['wallet.default_currency' => 'KWD']);

    expect($bob->balance())->toBe('0.000')
        ->and($bob->balance('savings'))->toBe('0.000')
        ->and($bob->hasWallet())->toBeFalse();
});

it('shows decimal strings in toArray() and JSON', function (): void {
    $this->wallet->setMinBalance('-12.5');
    $tx = $this->wallet->deposit(250.5);

    $wallet = $this->wallet->toArray();
    $entry = $tx->toArray();

    expect($wallet['balance'])->toBe('250.50')
        ->and($wallet['min_balance'])->toBe('-12.50')
        ->and($wallet['scale'])->toBe(2)
        ->and($entry['amount'])->toBe('250.50')
        ->and($entry['balance_after'])->toBe('250.50')
        ->and(json_decode($this->wallet->toJson(), true))->toMatchArray(['balance' => '250.50', 'min_balance' => '-12.50'])
        ->and(json_decode($tx->toJson(), true))->toMatchArray(['amount' => '250.50', 'balance_after' => '250.50']);

    // A wallet with its entries (which point back at it) serializes once.
    $loaded = Wallet::with('transactions')->findOrFail($this->wallet->id)->toArray();

    expect($loaded['balance'])->toBe('250.50')
        ->and($loaded['transactions'][0]['amount'])->toBe('250.50');
});

it('reports InsufficientFunds in decimal strings with the currency', function (): void {
    $this->wallet->deposit('10.50');
    $this->wallet->setMinBalance(-2);

    try {
        $this->wallet->withdraw('12.51');
        $this->fail('Expected InsufficientFunds.');
    } catch (InsufficientFunds $e) {
        expect($e->wallet)->toBe($this->wallet)
            ->and($e->requested)->toBe('12.51')
            ->and($e->balance)->toBe('10.50')
            ->and($e->minBalance)->toBe('-2.00')
            ->and($e->available)->toBe('12.50')
            ->and($e->getMessage())->toBe(sprintf(
                'Insufficient funds in wallet [%d]: requested 12.51 EGP, available 12.50 EGP (balance 10.50 EGP, minimum balance -2.00 EGP).',
                $this->wallet->id,
            ));
    }
});

it('never lets the scale change once the wallet exists', function (): void {
    $snapshot = $this->walletRow($this->wallet->id);

    expect(fn () => $this->wallet->forceFill(['scale' => 3])->save())->toThrow(ImmutableWallet::class, '[scale]')
        ->and(fn () => Wallet::query()->whereKey($this->wallet->id)->update(['scale' => 3]))->toThrow(ImmutableWallet::class, '[scale]')
        ->and(fn () => Wallet::query()->increment('scale'))->toThrow(ImmutableWallet::class)
        ->and($this->walletRow($this->wallet->id))->toBe($snapshot);
});

it('keeps reading existing balances with their own scale after the config changes', function (): void {
    $this->wallet->deposit('12.34');

    config(['wallet.currency_scales.EGP' => 3]);

    $fresh = Wallet::findOrFail($this->wallet->id);

    expect($fresh->scale())->toBe(2)
        ->and($fresh->balance)->toBe('12.34')
        ->and($fresh->transactions()->firstOrFail()->amount)->toBe('12.34')
        ->and($this->user->balance())->toBe('12.34')
        ->and(fn () => $fresh->deposit('0.001'))->toThrow(InvalidAmount::class)
        // New EGP wallets get the new scale.
        ->and($this->user->wallet('later')->scale())->toBe(3);
});

it('refuses an invalid scale in the config', function (array $config, string $key): void {
    config($config);

    expect(fn () => $this->user->wallet('new'))->toThrow(InvalidConfiguration::class, "[{$key}]")
        ->and(fn () => $this->user->balance('missing'))->toThrow(InvalidConfiguration::class)
        ->and($this->user->hasWallet('new'))->toBeFalse();
})->with([
    'default scale 1' => [['wallet.scale' => 1], 'wallet.scale'],
    'default scale 4' => [['wallet.scale' => 4], 'wallet.scale'],
    'default scale string' => [['wallet.scale' => 'two'], 'wallet.scale'],
    'currency scale 1' => [['wallet.currency_scales.KWD' => 1], 'wallet.currency_scales.KWD'],
    'currency scale 4' => [['wallet.currency_scales.USD' => 4], 'wallet.currency_scales.USD'],
    'currency scale 0' => [['wallet.currency_scales.JPY' => 0], 'wallet.currency_scales.JPY'],
]);

it('refuses currency_scales that is not an array', function (): void {
    config(['wallet.currency_scales' => 3]);

    expect(fn () => $this->user->wallet('new'))->toThrow(InvalidConfiguration::class, 'wallet.currency_scales');
});

it('refuses an explicit scale other than 2 or 3', function (): void {
    expect(fn () => (new Wallet)->forceFill([
        'holder_type' => 'user', 'holder_id' => $this->user->id,
        'name' => 'odd', 'currency' => 'EGP', 'scale' => 4,
    ])->save())->toThrow(InvalidWalletAttribute::class, 'Invalid wallet scale [4]');

    expect($this->user->hasWallet('odd'))->toBeFalse();
});

it('fills in the scale on every creation path', function (): void {
    $wallet = $this->user->wallets()->withAttributes(['name' => 'kwd', 'currency' => 'KWD'])->create();

    expect($wallet->scale())->toBe(3)
        ->and((int) $this->walletRow($wallet->id)['scale'])->toBe(3);
});

it('refuses to read amounts of a wallet loaded without its scale', function (): void {
    $partial = Wallet::query()->whereKey($this->wallet->id)->first(['id', 'balance']);

    expect(fn () => $partial?->balance)->toThrow(InvalidWalletAttribute::class, 'scale] column was not selected')
        ->and(fn () => Wallet::query()->whereKey($this->wallet->id)->value('balance'))->toThrow(InvalidWalletAttribute::class)
        ->and($partial?->balanceInMinor())->toBe(0);
});

it('replays a keyed deposit across equivalent amount spellings', function (): void {
    $first = $this->wallet->deposit(100.5, idempotencyKey: 'k');
    $again = $this->wallet->deposit('100.50', idempotencyKey: 'k');
    $third = $this->wallet->deposit('100.500', idempotencyKey: 'k');

    expect($again->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($this->transactionRows())->toHaveCount(1)
        ->and(fn () => $this->wallet->deposit('100.51', idempotencyKey: 'k'))->toThrow(IdempotencyConflict::class);
});

it('fingerprints the minor-unit delta, whatever spelling produced it', function (): void {
    $hashes = array_map(
        fn (int|float|string $amount): string => Fingerprint::for($this->wallet->deposit($amount)->amountInMinor(), new Details('deposit')),
        [100.5, 100.50, '100.50', '100.5'],
    );

    expect(array_unique($hashes))->toBe([Fingerprint::for(100_50, new Details('deposit'))]);
});

it('gives entries read through the wallet their wallet without extra queries', function (): void {
    $this->wallet->deposit(1);
    $this->wallet->deposit(2);
    $fresh = Wallet::findOrFail($this->wallet->id);

    $amounts = [];

    $queries = $this->queriesDuring(function () use ($fresh, &$amounts): void {
        $amounts = $fresh->transactions->map(fn (WalletTransaction $tx): string => $tx->amount.'/'.$tx->balance_after)->all();
    });

    expect($queries)->toHaveCount(1)
        ->and($amounts)->toBe(['1.00/1.00', '2.00/3.00'])
        ->and($fresh->transactions->first()?->wallet)->toBe($fresh);
});

it('eager loads the wallet of entries queried directly', function (): void {
    $this->wallet->deposit(1);
    $this->wallet->deposit(2);

    $amounts = [];

    $queries = $this->queriesDuring(function () use (&$amounts): void {
        $amounts = WalletTransaction::query()->orderBy('id')->get()->map(fn (WalletTransaction $tx): string => $tx->amount)->all();
    });

    // The entries, then their wallets in one query: no N+1.
    expect($queries)->toHaveCount(2)
        ->and($amounts)->toBe(['1.00', '2.00']);
});

it('reads amounts on raw-inserted entries through their wallet', function (): void {
    $kwd = $this->user->createWallet('kwd', 'KWD');
    $id = $this->insertTransactionRow($kwd->id, ['amount' => -1_234, 'balance_after' => 5]);

    $tx = WalletTransaction::findOrFail($id);

    expect($tx->amount)->toBe('-1.234')
        ->and($tx->balance_after)->toBe('0.005')
        ->and($tx->isDebit())->toBeTrue()
        ->and(DB::table($this->transactionsTable())->where('id', $id)->value('amount'))->toEqual(-1_234);
});
