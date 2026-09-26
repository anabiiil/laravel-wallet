<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Anabil\Wallet\Contracts\TransactionType;

enum AppType: string implements TransactionType
{
    case Purchase = 'purchase';
    case BankWithdrawal = 'bank_withdrawal';
    case Cashback = 'cashback';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::BankWithdrawal => 'Bank withdrawal',
            self::Cashback => 'Cashback',
        };
    }
}
