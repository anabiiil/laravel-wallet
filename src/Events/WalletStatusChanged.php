<?php

declare(strict_types=1);

namespace Anabil\Wallet\Events;

use Anabil\Wallet\Enums\WalletStatus;
use Anabil\Wallet\Models\Wallet;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Fired once for every status change the package writes (freeze(),
 * unfreeze(), close()); never for a call that changed nothing because the
 * wallet already had the requested status. When the change happens inside
 * a database transaction, listeners run only after the outermost
 * transaction commits, and never if it rolls back.
 */
final class WalletStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly WalletStatus $from,
        public readonly WalletStatus $to,
        public readonly ?string $reason = null,
        public readonly ?Model $performedBy = null,
    ) {}
}
