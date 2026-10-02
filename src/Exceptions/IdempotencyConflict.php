<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;
use Throwable;

/**
 * Thrown when an idempotency key is reused on the same wallet for a
 * different operation (another amount, direction, type, reference, actor,
 * description or meta, or a transfer to another recipient). Nothing is
 * written.
 */
class IdempotencyConflict extends WalletException
{
    public function __construct(
        public readonly string $key,
        public readonly int|string $walletId,
        public readonly int|string $transactionId,
        ?Throwable $previous = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
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

    /**
     * The entry found under the key has the transfer's fingerprint but is
     * not the sender leg of a complete transfer (it is not a transfer_out
     * entry, or its recipient entry is missing), so it cannot be replayed.
     */
    public static function incompleteTransfer(Wallet $wallet, string $key, WalletTransaction $existing, ?Throwable $previous = null): self
    {
        $walletId = self::id($wallet->getKey());
        $transactionId = self::id($existing->getKey());

        return new self($key, $walletId, $transactionId, $previous, sprintf(
            'The idempotency key [%s] on wallet [%s] belongs to transaction [%s], which is not part of a complete transfer, so the transfer cannot be replayed. Use a new key for a new operation.',
            $key,
            (string) $walletId,
            (string) $transactionId,
        ));
    }

    private static function id(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : (string) json_encode($key);
    }
}
