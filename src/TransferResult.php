<?php

declare(strict_types=1);

namespace Anabil\Wallet;

use Anabil\Wallet\Models\WalletTransaction;

/**
 * The outcome of a transfer: the sender's entry ($out, negative amount),
 * the recipient's entry ($in, positive amount) and the uuid grouping them.
 */
final readonly class TransferResult
{
    public function __construct(
        public WalletTransaction $out,
        public WalletTransaction $in,
        public string $group,
        private bool $replayed = false,
    ) {}

    /**
     * The amount moved, positive, in minor units.
     */
    public function amount(): int
    {
        return $this->in->amount;
    }

    /**
     * Whether this is the transfer recorded earlier under the same
     * idempotency key rather than a new one. A replay changes nothing and
     * dispatches no events.
     */
    public function replayed(): bool
    {
        return $this->replayed;
    }
}
