<?php

declare(strict_types=1);

namespace Anabil\Wallet\Ledger;

use Anabil\Wallet\Models\Wallet;

/**
 * One ledger entry of a posting: the wallet it moves money on, the signed
 * amount (positive credit, negative debit) and its details. A posting of
 * several legs (a transfer, later reversals and fees) is written in one
 * database transaction by LedgerWriter.
 *
 * The idempotency key and fingerprint, when given, are stored on this leg
 * only: the key is scoped to this leg's wallet.
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
    ) {}
}
