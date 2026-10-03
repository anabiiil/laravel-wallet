<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;

/**
 * One ledger entry of a posting: the wallet it moves money on, the signed
 * amount (positive credit, negative debit) and its details. A posting of
 * several legs (a transfer, the reversal of a transfer, later fees) is
 * written in one database transaction by LedgerWriter.
 *
 * The idempotency key and fingerprint, when given, are stored on this leg
 * only: the key is scoped to this leg's wallet.
 *
 * $enforceFloor only matters for a debit: when false the guarded UPDATE
 * does not check the wallet's minimum balance (it still refuses to go past
 * the smallest storable balance). Only a forced reversal turns it off.
 *
 * $reverses is the entry this leg reverses: its id is stored in
 * reverses_id and its reference is copied onto the new entry.
 *
 * @internal
 */
final readonly class Leg
{
    public function __construct(
        public Wallet $wallet,
        public int $delta,
        public Details $details,
        public ?string $idempotencyKey = null,
        public ?string $idempotencyHash = null,
        public bool $enforceFloor = true,
        public ?WalletTransaction $reverses = null,
    ) {}
}
