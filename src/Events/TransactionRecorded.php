<?php

declare(strict_types=1);

namespace Anabil\Wallet\Events;

use Anabil\Wallet\Models\WalletTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Fired once for every ledger entry the package writes. When the write
 * happens inside a database transaction, listeners run only after the
 * outermost transaction commits, and never if it rolls back.
 */
final class TransactionRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly WalletTransaction $transaction,
    ) {}
}
