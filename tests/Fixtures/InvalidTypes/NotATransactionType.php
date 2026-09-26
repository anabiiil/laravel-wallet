<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures\InvalidTypes;

/**
 * Invalid for wallet.types: does not implement TransactionType.
 */
enum NotATransactionType: string
{
    case Bonus = 'bonus';
}
