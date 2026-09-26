<?php

declare(strict_types=1);

namespace Anabil\Wallet\Contracts;

/**
 * A kind of ledger entry. Implement it on a string-backed enum and point
 * the "wallet.types" config at that enum to give your application its own
 * transaction types next to the package's CoreType cases.
 */
interface TransactionType
{
    /**
     * A human-readable name for the type.
     */
    public function label(): string;
}
