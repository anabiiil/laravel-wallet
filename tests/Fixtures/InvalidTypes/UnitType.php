<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures\InvalidTypes;

use Anabil\Wallet\Contracts\TransactionType;

/**
 * Invalid for wallet.types: not backed at all.
 */
enum UnitType implements TransactionType
{
    case Bonus;

    public function label(): string
    {
        return 'Bonus';
    }
}
