<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures\InvalidTypes;

use Anabil\Wallet\Contracts\TransactionType;

/**
 * Invalid for wallet.types: not string-backed.
 */
enum IntBackedType: int implements TransactionType
{
    case Bonus = 1;

    public function label(): string
    {
        return 'Bonus';
    }
}
