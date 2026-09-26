<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures\InvalidTypes;

use Anabil\Wallet\Contracts\TransactionType;

/**
 * Invalid for wallet.types: a value the type column cannot hold.
 */
enum BadValueType: string implements TransactionType
{
    case Bonus = 'Big Bonus';

    public function label(): string
    {
        return 'Big bonus';
    }
}
