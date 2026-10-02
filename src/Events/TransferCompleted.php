<?php

declare(strict_types=1);

namespace Anabil\Wallet\Events;

use Anabil\Wallet\TransferResult;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Fired once for every transfer the package writes, after the
 * TransactionRecorded events of its two entries. When the transfer happens
 * inside a database transaction, listeners run only after the outermost
 * transaction commits, and never if it rolls back. Not fired on replay.
 */
final class TransferCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TransferResult $transfer,
    ) {}
}
