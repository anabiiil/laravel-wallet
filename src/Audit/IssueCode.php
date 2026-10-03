<?php

declare(strict_types=1);

namespace Anabil\Wallet\Audit;

/**
 * Every problem WalletAuditor can find. Each case is a fact about the
 * ledger that Eloquent alone could never produce: it can only exist because
 * something wrote to the tables outside the package (raw SQL, a manual DB
 * edit, or a bug), which is exactly what the audit exists to catch.
 */
enum IssueCode: string
{
    /**
     * wallets.balance does not equal the sum of that wallet's ledger rows.
     */
    case BalanceMismatch = 'balance_mismatch';

    /**
     * The balance_after of a wallet's highest-id ledger row does not equal
     * wallets.balance.
     */
    case LastBalanceAfterMismatch = 'last_balance_after_mismatch';

    /**
     * Some ledger row's balance_after does not equal the running sum of
     * amounts up to and including that row.
     */
    case BrokenChain = 'broken_chain';

    /**
     * The wallet's balance is below its min_balance. Not an error: this is
     * legitimate once a credit line is reduced after money was already
     * spent against it.
     */
    case BelowFloor = 'below_floor';

    /**
     * The wallet's scale is neither 2 nor 3.
     */
    case InvalidScale = 'invalid_scale';

    /**
     * A transfer group's legs do not sum to zero.
     */
    case UnbalancedGroup = 'unbalanced_group';

    /**
     * A transfer group does not have exactly one transfer_out and exactly
     * one transfer_in row.
     */
    case MalformedTransfer = 'malformed_transfer';

    /**
     * A transfer_out or transfer_in row has no group_uuid.
     */
    case TransferWithoutGroup = 'transfer_without_group';

    /**
     * Whether this code fails Report::passed() (Error) or is merely
     * informational (Warning).
     */
    public function severity(): Severity
    {
        return match ($this) {
            self::BelowFloor => Severity::Warning,
            default => Severity::Error,
        };
    }
}
