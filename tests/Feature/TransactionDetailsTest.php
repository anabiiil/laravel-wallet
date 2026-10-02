<?php

declare(strict_types=1);

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Tests\Fixtures\Admin;
use Anabil\Wallet\Tests\Fixtures\AppType;
use Anabil\Wallet\Tests\Fixtures\Order;
use Anabil\Wallet\Tests\Fixtures\OrderPayment;
use Anabil\Wallet\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->wallet = User::create(['name' => 'Ada'])->wallet();
    $this->wallet->deposit(1000);
    $this->admin = Admin::create(['name' => 'Root']);
    $this->order = Order::create();
    $this->baseline = count($this->transactionRows());
});

it('stores the details given as named arguments', function (): void {
    $tx = $this->wallet->withdraw(
        25,
        'fee.card',
        reference: $this->order,
        description: 'Card fee',
        meta: ['card' => '4242', 'nested' => ['a' => true]],
        performedBy: $this->admin,
    );

    $row = $this->transactionRows()[$this->baseline];

    expect($row['type'])->toBe('fee.card')
        ->and($row['reference_type'])->toBe('order')
        ->and((int) $row['reference_id'])->toBe($this->order->id)
        ->and($row['performed_by_type'])->toBe('admin')
        ->and((int) $row['performed_by_id'])->toBe($this->admin->id)
        ->and($row['description'])->toBe('Card fee')
        ->and(json_decode((string) $row['meta'], true))->toBe(['card' => '4242', 'nested' => ['a' => true]])
        ->and($tx->type)->toBe('fee.card')
        ->and($tx->meta)->toBe(['card' => '4242', 'nested' => ['a' => true]])
        ->and($tx->reference_type)->toBe('order')
        ->and($tx->performed_by_type)->toBe('admin');
});

it('takes the details from a WalletOperation', function (): void {
    config(['wallet.types' => AppType::class]);

    $tx = $this->wallet->withdraw(10, new OrderPayment($this->order));

    $row = $this->transactionRows()[$this->baseline];

    expect($row['type'])->toBe('purchase')
        ->and($row['reference_type'])->toBe('order')
        ->and((int) $row['reference_id'])->toBe($this->order->id)
        ->and($row['description'])->toBe('Payment for order #'.$this->order->id)
        ->and(json_decode((string) $row['meta'], true))->toBe(['order_id' => $this->order->id])
        ->and($row['performed_by_type'])->toBeNull()
        ->and($tx->type)->toBe(AppType::Purchase)
        ->and($tx->reference)->toBe($this->order);
});

it('accepts performedBy alongside an operation', function (): void {
    config(['wallet.types' => AppType::class]);

    $tx = $this->wallet->withdraw(10, new OrderPayment($this->order), performedBy: $this->admin);

    expect($tx->performed_by_type)->toBe('admin')
        ->and($tx->performed_by_id)->toBe($this->admin->id)
        ->and($tx->performedBy)->toBe($this->admin);
});

it('refuses details passed alongside an operation', function (string $argument): void {
    config(['wallet.types' => AppType::class]);

    $extra = [
        'description' => ['description' => 'Other'],
        'reference' => ['reference' => $this->admin],
        'meta' => ['meta' => ['x' => 1]],
    ][$argument];

    expect(fn () => $this->wallet->withdraw(10, new OrderPayment($this->order), ...$extra))
        ->toThrow(InvalidTransaction::class, 'already provides');

    expect($this->transactionRows())->toHaveCount($this->baseline);
})->with(['description', 'reference', 'meta']);

it('refuses unsaved models', function (string $role): void {
    $args = [$role => new Order];

    expect(fn () => $this->wallet->deposit(1, ...$args))
        ->toThrow(InvalidTransaction::class, "The {$role} model");

    expect($this->transactionRows())->toHaveCount($this->baseline);
})->with(['reference', 'performedBy']);

it('refuses a description longer than 255 characters', function (): void {
    expect(fn () => $this->wallet->deposit(1, description: str_repeat('é', 256)))
        ->toThrow(InvalidTransaction::class, '256 characters');

    $tx = $this->wallet->deposit(1, description: str_repeat('é', 255));

    expect(mb_strlen((string) $tx->description))->toBe(255);
});

it('stores a blank description as null', function (): void {
    $tx = $this->wallet->deposit(1, description: "  \n ");

    expect($tx->description)->toBeNull()
        ->and($this->transactionRows()[$this->baseline]['description'])->toBeNull();
});

it('refuses meta that cannot be JSON encoded', function (Closure $meta): void {
    expect(fn () => $this->wallet->deposit(1, meta: $meta()))
        ->toThrow(InvalidTransaction::class, 'cannot be stored as JSON');

    expect($this->transactionRows())->toHaveCount($this->baseline);
})->with([
    'INF' => fn () => ['rate' => INF],
    'NAN' => fn () => ['rate' => NAN],
    'resource' => fn () => ['handle' => fopen('php://memory', 'r')],
    'invalid UTF-8' => fn () => ['name' => "\xB1\x31"],
]);

it('stores empty meta as NULL', function (): void {
    $tx = $this->wallet->deposit(1, meta: []);

    expect($tx->meta)->toBeNull()
        ->and($this->transactionRows()[$this->baseline]['meta'])->toBeNull();
});

it('uses the default core types', function (): void {
    expect($this->wallet->deposit('0.01')->getRawOriginal('type'))->toBe(CoreType::Deposit->value)
        ->and($this->wallet->withdraw('0.01')->getRawOriginal('type'))->toBe(CoreType::Withdraw->value);
});
