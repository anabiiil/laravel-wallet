<?php

declare(strict_types=1);

namespace Anabil\Wallet\Events;

use Anabil\Wallet\Models\WalletTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Fired once for every entry the package reverses (twice for a transfer:
 * once per leg), after the TransactionRecorded events of the new entries.
 * When the reversal happens inside a database transaction, listeners run
 * only after the outermost transaction commits, and never if it rolls
 * back. Not fired on replay.
 */
final class TransactionReversed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly WalletTransaction $original,
        public readonly WalletTransaction $reversal,
    ) {}
}
