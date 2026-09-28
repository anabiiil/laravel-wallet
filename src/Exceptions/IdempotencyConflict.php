<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Throwable;

/**
 * Thrown when an idempotency key is reused on the same wallet for a
 * different operation (another amount, direction, type, reference, actor,
 * description or meta). Nothing is written.
 */
class IdempotencyConflict extends WalletException
{
    public function __construct(
        public readonly string $key,
        public readonly int|string $walletId,
        public readonly int|string $transactionId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'The idempotency key [%s] was already used on wallet [%s] for a different operation (transaction [%s]). Use a new key for a new operation.',
            $key,
            (string) $walletId,
            (string) $transactionId,
        ), 0, $previous);
    }

    public static function for(Wallet $wallet, string $key, WalletTransaction $existing, ?Throwable $previous = null): self
    {
        return new self($key, self::id($wallet->getKey()), self::id($existing->getKey()), $previous);
    }

    private static function id(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : (string) json_encode($key);
    }
}
