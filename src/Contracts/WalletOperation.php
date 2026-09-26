<?php

declare(strict_types=1);

namespace Anabil\Wallet\Contracts;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable description of a money movement ("pay for this order",
 * "refund this booking"). Pass one as the $type of deposit() / withdraw()
 * instead of repeating the reference, description and meta at every call
 * site.
 */
interface WalletOperation
{
    /**
     * The transaction type: a CoreType case, a case of the enum configured in
     * "wallet.types", or one of that enum's values as a string.
     */
    public function type(): string|BackedEnum;

    /**
     * The model the entry is about (an order, an invoice...), or null.
     */
    public function reference(): ?Model;

    /**
     * A short human-readable note, at most 255 characters.
     */
    public function description(): ?string;

    /**
     * Extra JSON-encodable data stored with the entry.
     *
     * @return array<array-key, mixed>
     */
    public function meta(): array;
}
