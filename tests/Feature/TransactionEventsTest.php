<?php

declare(strict_types=1);

use Anabil\Wallet\Events\TransactionRecorded;
use Anabil\Wallet\Exceptions\InsufficientFunds;
use Anabil\Wallet\Exceptions\InvalidTransaction;
use Anabil\Wallet\Tests\Fixtures\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * A real listener is used instead of Event::fake(): the fake records events
 * immediately and would hide the after-commit behaviour under test.
 */

beforeEach(function (): void {
    $this->wallet = User::create(['name' => 'Ada'])->wallet();
    $this->recorded = new ArrayObject;

    Event::listen(TransactionRecorded::class, function (TransactionRecorded $event): void {
        $this->recorded[] = [
            'id' => $event->transaction->id,
            'rows' => count($this->transactionRows()),
            'level' => DB::transactionLevel(),
        ];
    });
});

it('is dispatched after commit', function (): void {
    expect(new TransactionRecorded($this->wallet->deposit(1)))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('is dispatched once per write with the entry', function (): void {
    $deposit = $this->wallet->deposit(100);
    $withdrawal = $this->wallet->withdraw(40);

    expect($this->recorded->getArrayCopy())->toHaveCount(2)
        ->and($this->recorded[0]['id'])->toBe($deposit->id)
        ->and($this->recorded[1]['id'])->toBe($withdrawal->id);
});

it('is not dispatched when the write fails', function (): void {
    expect(fn () => $this->wallet->withdraw(1))->toThrow(InsufficientFunds::class)
        ->and(fn () => $this->wallet->deposit(1, 'reversal'))->toThrow(InvalidTransaction::class);

    expect($this->recorded->getArrayCopy())->toBe([]);
});

it('waits for the outer transaction to commit', function (): void {
    // RefreshDatabase wraps each test in a transaction (level 1) that the
    // testing transactions manager treats as the root.
    $baseLevel = DB::transactionLevel();

    DB::transaction(function (): void {
        $this->wallet->deposit(100);
        $this->wallet->deposit(200);

        expect($this->recorded->getArrayCopy())->toBe([]);
    });

    expect($this->recorded->getArrayCopy())->toHaveCount(2)
        ->and($this->recorded[0]['level'])->toBe($baseLevel)
        ->and($this->recorded[1]['rows'])->toBe(2);
});

it('is never dispatched when the outer transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            $this->wallet->deposit(100);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($this->recorded->getArrayCopy())->toBe([])
        ->and($this->transactionRows())->toBe([]);
});
