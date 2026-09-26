<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures\InvalidTypes;

use Anabil\Wallet\Contracts\TransactionType;

/**
 * Invalid for wallet.types: reuses a core type value.
 */
enum CollidingType: string implements TransactionType
{
    case Bonus = 'bonus';
    case Refund = 'reversal';

    public function label(): string
    {
        return $this->name;
    }
}
