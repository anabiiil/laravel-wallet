<?php

declare(strict_types=1);

use Anabil\Wallet\Contracts\TransactionType;
use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Exceptions\ImmutableTransaction;
use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Models\WalletTransaction;
use Anabil\Wallet\Support\Types;
use Anabil\Wallet\Tests\Fixtures\AppType;
use Anabil\Wallet\Tests\Fixtures\InvalidTypes\BadValueType;
use Anabil\Wallet\Tests\Fixtures\InvalidTypes\CollidingType;
use Anabil\Wallet\Tests\Fixtures\InvalidTypes\IntBackedType;
use Anabil\Wallet\Tests\Fixtures\InvalidTypes\NotATransactionType;
use Anabil\Wallet\Tests\Fixtures\InvalidTypes\UnitType;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->wallet = User::create(['name' => 'Ada'])->wallet();
    $this->wallet->deposit(1000);
});

it('defaults to no app enum', function (): void {
    expect(config('wallet.types'))->toBeNull()
        ->and(Types::configuredEnum())->toBeNull();
});

it('labels the core types', function (): void {
    expect(CoreType::Deposit)->toBeInstanceOf(TransactionType::class)
        ->and(CoreType::Deposit->label())->toBe('Deposit')
        ->and(CoreType::Withdraw->label())->toBe('Withdrawal')
        ->and(CoreType::TransferIn->label())->toBe('Incoming transfer')
        ->and(CoreType::TransferOut->label())->toBe('Outgoing transfer')
        ->and(CoreType::Reversal->label())->toBe('Reversal');
});

it('accepts core enum cases and their string values', function (): void {
    expect($this->wallet->deposit('0.01', CoreType::Deposit)->type)->toBe(CoreType::Deposit)
        ->and($this->wallet->withdraw('0.01', 'withdraw')->type)->toBe(CoreType::Withdraw);
});

it('accepts well-formed free strings when no enum is configured', function (string $type): void {
    $tx = $this->wallet->deposit('0.01', $type);

    expect($tx->type)->toBe($type)
        ->and($tx->getRawOriginal('type'))->toBe($type);
})->with(['cashback', 'fee.card', 'promo:summer-2026', 'a', 'x'.str_repeat('y', 31)]);

it('rejects badly formatted strings when no enum is configured', function (string $type): void {
    expect(fn () => $this->wallet->deposit('0.01', $type))->toThrow(InvalidTransaction::class, 'Invalid transaction type');
})->with(['', 'Cashback', '1st', 'has space', 'x'.str_repeat('y', 32), "cashback\n", 'émoji']);

it('rejects enum cases that are neither core nor configured', function (): void {
    expect(fn () => $this->wallet->deposit('0.01', AppType::Cashback))
        ->toThrow(InvalidTransaction::class, 'configure your enum')
        ->and(fn () => $this->wallet->deposit('0.01', WalletStatus::Active))
        ->toThrow(InvalidTransaction::class, 'is not supported');
});

it('rejects the reserved core types for app calls', function (string|CoreType $type): void {
    $count = count($this->transactionRows());

    expect(fn () => $this->wallet->deposit('0.01', $type))->toThrow(InvalidTransaction::class, 'reserved')
        ->and(fn () => $this->wallet->withdraw('0.01', $type))->toThrow(InvalidTransaction::class, 'reserved');

    config(['wallet.types' => AppType::class]);

    expect(fn () => $this->wallet->deposit('0.01', $type))->toThrow(InvalidTransaction::class, 'reserved');

    expect($this->transactionRows())->toHaveCount($count);
})->with([
    CoreType::TransferIn, CoreType::TransferOut, CoreType::Reversal,
    'transfer_in', 'transfer_out', 'reversal',
]);

it('lets internal writes use the reserved types', function (): void {
    expect(Types::resolveForWrite(CoreType::Reversal, internal: true))->toBe('reversal')
        ->and(Types::resolveForWrite('transfer_in', internal: true))->toBe('transfer_in');
});

describe('with an app enum configured', function (): void {
    beforeEach(function (): void {
        config(['wallet.types' => AppType::class]);
    });

    it('accepts its cases and values', function (): void {
        $purchase = $this->wallet->withdraw('0.01', AppType::Purchase);
        $bank = $this->wallet->withdraw('0.01', 'bank_withdrawal');

        expect($purchase->type)->toBe(AppType::Purchase)
            ->and($purchase->getRawOriginal('type'))->toBe('purchase')
            ->and($bank->type)->toBe(AppType::BankWithdrawal)
            ->and($bank->type->label())->toBe('Bank withdrawal');
    });

    it('still accepts the core types', function (): void {
        expect($this->wallet->deposit('0.01')->type)->toBe(CoreType::Deposit)
            ->and($this->wallet->deposit('0.01', 'deposit')->type)->toBe(CoreType::Deposit);
    });

    it('rejects unknown strings', function (): void {
        expect(fn () => $this->wallet->deposit('0.01', 'cashback_v2'))
            ->toThrow(InvalidTransaction::class, 'Unknown transaction type [cashback_v2]');
    });

    it('reads unknown stored values back as raw strings', function (): void {
        $id = $this->insertTransactionRow($this->wallet->id, ['type' => 'legacy_bonus']);

        expect(WalletTransaction::findOrFail($id)->type)->toBe('legacy_bonus');
    });
});

it('reads stored values as enums or raw strings', function (): void {
    $cashback = $this->insertTransactionRow($this->wallet->id, ['type' => 'cashback']);
    $reversal = $this->insertTransactionRow($this->wallet->id, ['type' => 'reversal']);

    expect(WalletTransaction::findOrFail($cashback)->type)->toBe('cashback')
        ->and(WalletTransaction::findOrFail($reversal)->type)->toBe(CoreType::Reversal);

    config(['wallet.types' => AppType::class]);

    $tx = WalletTransaction::findOrFail($cashback);

    expect($tx->type)->toBe(AppType::Cashback)
        ->and($tx->type->label())->toBe('Cashback')
        ->and($tx->getRawOriginal('type'))->toBe('cashback')
        ->and($tx->isDirty())->toBeFalse()
        ->and($tx->getAttributes()['type'])->toBe('cashback')
        ->and($tx->toArray()['type'])->toBe(AppType::Cashback);
});

it('rejects an invalid types config', function (mixed $config, string $message): void {
    config(['wallet.types' => $config]);

    expect(fn () => $this->wallet->deposit('0.01'))->toThrow(InvalidConfiguration::class, $message)
        ->and(fn () => Types::resolveForRead('cashback'))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'not a class' => ['App\\Missing', 'string-backed enum'],
    'not an enum' => [Order::class, 'string-backed enum'],
    'not a string' => [[AppType::class], 'string-backed enum'],
    'int-backed enum' => [IntBackedType::class, 'string-backed enum'],
    'unit enum' => [UnitType::class, 'string-backed enum'],
    'not a TransactionType' => [NotATransactionType::class, 'must implement'],
    'collides with core' => [CollidingType::class, 'defines the value [reversal], which is a core transaction type'],
    'bad value' => [BadValueType::class, 'not a valid transaction type'],
]);

it('keeps the ledger read-only despite the type accessor', function (): void {
    $tx = $this->wallet->deposit('0.01', 'cashback');

    expect(fn () => $tx->save())->not->toThrow(Throwable::class)
        ->and(fn () => $tx->forceFill(['type' => 'other'])->save())->toThrow(ImmutableTransaction::class);
});
