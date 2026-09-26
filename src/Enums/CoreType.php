<?php

declare(strict_types=1);

namespace Anabil\Wallet\Enums;

use Anabil\Wallet\Contracts\TransactionType;

/**
 * The transaction types the package itself knows about. Deposit and Withdraw
 * are the defaults for deposit() / withdraw(); the others are reserved for
 * entries the package writes itself (transfers and reversals).
 */
enum CoreType: string implements TransactionType
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Reversal = 'reversal';

    /**
     * Types only the package may write.
     *
     * @return list<self>
     */
    public static function reserved(): array
    {
        return [self::TransferIn, self::TransferOut, self::Reversal];
    }

    public function isReserved(): bool
    {
        return in_array($this, self::reserved(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdraw => 'Withdrawal',
            self::TransferIn => 'Incoming transfer',
            self::TransferOut => 'Outgoing transfer',
            self::Reversal => 'Reversal',
        };
    }
}
