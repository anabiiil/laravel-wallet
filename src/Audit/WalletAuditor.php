<?php

declare(strict_types=1);

namespace Anabil\Wallet\Audit;

use Anabil\Wallet\Enums\CoreType;
use Anabil\Wallet\Support\Amount;
use Anabil\Wallet\Support\Models;
use Anabil\Wallet\Support\Scales;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;

/**
 * Proves the ledger's invariants still hold. Through Eloquent alone nothing
 * can break them (see Wallet and WalletTransaction); they can only break
 * via raw SQL, a manual database edit, or a bug, which is exactly what this
 * class is checking for.
 *
 * Per wallet (balance_mismatch, last_balance_after_mismatch, broken_chain,
 * below_floor, invalid_scale): wallets are processed in chunks of ids
 * (default 500), lightest to heaviest. The id list itself is the only
 * thing ever held in full; everything else is read and discarded a chunk
 * at a time, so memory stays flat whatever the size of the tables.
 *
 * For each chunk, the balance/last-row/floor/scale checks come from a
 * single SQL statement (the wallets joined to a grouped aggregate of their
 * ledger rows and to their last row), so a concurrent write between two
 * separate reads can never manufacture a false positive: on MySQL InnoDB
 * and PostgreSQL a single statement sees one consistent snapshot. The
 * broken_chain check is a second single statement per chunk, built around
 * a window function (SUM(amount) OVER (PARTITION BY wallet_id ORDER BY id
 * ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)) that is filtered in an
 * outer query rather than inside the window clause itself, which is what
 * makes it portable to MySQL 8, PostgreSQL and SQLite 3.25+ alike.
 *
 * Per transfer group (unbalanced_group, malformed_transfer) and per loose
 * leg (transfer_without_group): streamed with a cursor rather than loaded
 * at once. A "transfer group" is any group_uuid carried by at least one
 * transfer_out or transfer_in row, but unbalanced_group sums every row
 * sharing that group_uuid, not only its transfer legs: later phases may add
 * more legs to a group (fees, reversals), and those must count towards it
 * balancing to zero. malformed_transfer still only counts the
 * transfer_out/transfer_in legs, since that check is specifically about
 * there being exactly one of each. When $walletIds scopes the audit, group
 * checks still cover every row of a group that touches one of those
 * wallets, even legs that sit on a wallet outside the scope.
 */
final class WalletAuditor
{
    /**
     * @param  list<int>|null  $walletIds  only these wallets (and the transfer groups touching them) when given; everything otherwise
     *
     * @throws InvalidArgumentException when $chunk is not a positive integer
     */
    public function audit(?array $walletIds = null, int $chunk = 500): Report
    {
        if ($chunk < 1) {
            throw new InvalidArgumentException(sprintf('The chunk size must be a positive integer, got [%d].', $chunk));
        }

        $walletClass = Models::wallet();
        $transactionClass = Models::transaction();
        $walletModel = new $walletClass;
        $transactionModel = new $transactionClass;

        $connection = $walletModel->getConnection();
        $walletsTable = $walletModel->getTable();
        $transactionsTable = $transactionModel->getTable();

        $ids = $this->walletIdsToCheck($connection, $walletsTable, $walletIds);

        $issues = [];
        $walletsChecked = 0;

        foreach (array_chunk($ids, $chunk) as $chunkIds) {
            $rows = $this->fetchWalletAggregates($connection, $walletsTable, $transactionsTable, $chunkIds);
            $walletsChecked += $rows->count();

            /** @var array<int, stdClass> $byId */
            $byId = [];

            foreach ($rows as $row) {
                $byId[(int) $row->wallet_id] = $row;

                foreach ($this->checkWallet($row) as $issue) {
                    $issues[] = $issue;
                }
            }

            $chain = $this->fetchBrokenChains($connection, $transactionsTable, $chunkIds);

            foreach ($this->firstBrokenRowPerWallet($chain) as $walletId => $data) {
                $issues[] = $this->brokenChainIssue($walletId, $data, $byId);
            }
        }

        [$groupIssues, $groupsChecked] = $this->auditGroups($connection, $walletsTable, $transactionsTable, $walletIds);

        foreach ($groupIssues as $issue) {
            $issues[] = $issue;
        }

        return new Report($walletsChecked, $groupsChecked, $issues);
    }

    /**
     * @param  list<int>|null  $walletIds
     * @return list<int> ascending, deduplicated
     */
    private function walletIdsToCheck(Connection $connection, string $walletsTable, ?array $walletIds): array
    {
        if ($walletIds === null) {
            $ids = array_map(
                static fn (mixed $id): int => (int) $id,
                $connection->table($walletsTable)->orderBy('id')->pluck('id')->all(),
            );
        } else {
            $ids = array_map(static fn (int $id): int => $id, $walletIds);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The single consistent-read statement behind balance_mismatch,
     * last_balance_after_mismatch, below_floor and invalid_scale: the
     * wallets joined to a per-wallet aggregate of their ledger rows (sum,
     * count, highest id) and, through that highest id, to the row itself.
     *
     * @param  list<int>  $ids  non-empty
     * @return Collection<int, stdClass>
     */
    private function fetchWalletAggregates(Connection $connection, string $walletsTable, string $transactionsTable, array $ids): Collection
    {
        $aggregate = $connection->table($transactionsTable)
            ->select('wallet_id')
            ->selectRaw('SUM(amount) as sum_amount, COUNT(*) as row_count, MAX(id) as last_id')
            ->whereIn('wallet_id', $ids)
            ->groupBy('wallet_id');

        return $connection->table($walletsTable.' as w')
            ->leftJoinSub($aggregate, 'agg', 'agg.wallet_id', '=', 'w.id')
            ->leftJoin($transactionsTable.' as lastrow', 'lastrow.id', '=', 'agg.last_id')
            ->whereIn('w.id', $ids)
            ->orderBy('w.id')
            ->get([
                'w.id as wallet_id',
                'w.balance as balance',
                'w.min_balance as min_balance',
                'w.scale as scale',
                'w.currency as currency',
                $connection->raw('COALESCE(agg.sum_amount, 0) as sum_amount'),
                $connection->raw('COALESCE(agg.row_count, 0) as row_count'),
                'lastrow.balance_after as last_balance_after',
            ]);
    }

    /**
     * @return list<Issue>
     */
    private function checkWallet(stdClass $row): array
    {
        $walletId = (int) $row->wallet_id;
        $balance = (int) $row->balance;
        $minBalance = (int) $row->min_balance;
        $scale = (int) $row->scale;
        $currency = (string) $row->currency;
        $sumAmount = (int) $row->sum_amount;
        $rowCount = (int) $row->row_count;
        $lastBalanceAfter = $row->last_balance_after === null ? null : (int) $row->last_balance_after;

        $issues = [];

        if ($balance !== $sumAmount) {
            $issues[] = new Issue(
                IssueCode::BalanceMismatch,
                walletId: $walletId,
                groupUuid: null,
                transactionId: null,
                message: sprintf(
                    'Wallet %d balance %s does not match the sum of its ledger rows (%s).',
                    $walletId,
                    $this->money($balance, $scale, $currency),
                    $this->money($sumAmount, $scale, $currency),
                ),
                context: ['balance' => $balance, 'sum_amount' => $sumAmount, 'difference' => $balance - $sumAmount],
            );
        }

        if ($rowCount > 0 && $lastBalanceAfter !== null && $lastBalanceAfter !== $balance) {
            $issues[] = new Issue(
                IssueCode::LastBalanceAfterMismatch,
                walletId: $walletId,
                groupUuid: null,
                transactionId: null,
                message: sprintf(
                    'Wallet %d balance %s does not match the balance_after (%s) of its latest ledger row.',
                    $walletId,
                    $this->money($balance, $scale, $currency),
                    $this->money($lastBalanceAfter, $scale, $currency),
                ),
                context: ['balance' => $balance, 'last_balance_after' => $lastBalanceAfter],
            );
        }

        if ($balance < $minBalance) {
            $issues[] = new Issue(
                IssueCode::BelowFloor,
                walletId: $walletId,
                groupUuid: null,
                transactionId: null,
                message: sprintf(
                    'Wallet %d balance %s is below its minimum balance %s.',
                    $walletId,
                    $this->money($balance, $scale, $currency),
                    $this->money($minBalance, $scale, $currency),
                ),
                context: ['balance' => $balance, 'min_balance' => $minBalance],
            );
        }

        if (! Scales::isAllowed($scale)) {
            $issues[] = new Issue(
                IssueCode::InvalidScale,
                walletId: $walletId,
                groupUuid: null,
                transactionId: null,
                message: sprintf('Wallet %d has scale %d, but only 2 or 3 are valid.', $walletId, $scale),
                context: ['scale' => $scale],
            );
        }

        return $issues;
    }

    /**
     * The single statement behind broken_chain: every ledger row of the
     * chunk's wallets, each carrying the running sum of its wallet's
     * amounts up to and including itself (the window function), filtered
     * in this outer query to only the rows where that running sum
     * disagrees with the stored balance_after.
     *
     * @param  list<int>  $ids  non-empty
     * @return Collection<int, stdClass>
     */
    private function fetchBrokenChains(Connection $connection, string $transactionsTable, array $ids): Collection
    {
        $chain = static function ($query) use ($transactionsTable, $ids): void {
            $query->from($transactionsTable)
                ->select(['id', 'wallet_id', 'balance_after'])
                ->selectRaw('SUM(amount) OVER (PARTITION BY wallet_id ORDER BY id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_balance')
                ->whereIn('wallet_id', $ids);
        };

        return $connection->table($chain, 'chain')
            ->whereColumn('balance_after', '<>', 'running_balance')
            ->orderBy('wallet_id')
            ->orderBy('id')
            ->get(['wallet_id', 'id', 'balance_after', 'running_balance']);
    }

    /**
     * Reduces the (already ordered) broken rows to, per wallet, the first
     * offending row and how many rows are affected.
     *
     * @param  Collection<int, stdClass>  $chain
     * @return array<int, array{row: stdClass, count: int}>
     */
    private function firstBrokenRowPerWallet(Collection $chain): array
    {
        $byWallet = [];

        foreach ($chain as $row) {
            $walletId = (int) $row->wallet_id;

            if (! isset($byWallet[$walletId])) {
                $byWallet[$walletId] = ['row' => $row, 'count' => 0];
            }

            $byWallet[$walletId]['count']++;
        }

        return $byWallet;
    }

    /**
     * @param  array{row: stdClass, count: int}  $data
     * @param  array<int, stdClass>  $byId  the chunk's wallet aggregate rows, for scale/currency
     */
    private function brokenChainIssue(int $walletId, array $data, array $byId): Issue
    {
        $row = $data['row'];
        $count = $data['count'];

        $scale = isset($byId[$walletId]) ? (int) $byId[$walletId]->scale : 2;
        $currency = isset($byId[$walletId]) ? (string) $byId[$walletId]->currency : '';

        $storedBalance = (int) $row->balance_after;
        $expectedBalance = (int) $row->running_balance;
        $firstBadId = (int) $row->id;

        return new Issue(
            IssueCode::BrokenChain,
            walletId: $walletId,
            groupUuid: null,
            transactionId: $firstBadId,
            message: sprintf(
                'Wallet %d has a broken running balance starting at ledger row %d: it stores balance_after %s but the rows up to it sum to %s (%d row%s affected).',
                $walletId,
                $firstBadId,
                $this->money($storedBalance, $scale, $currency),
                $this->money($expectedBalance, $scale, $currency),
                $count,
                $count === 1 ? '' : 's',
            ),
            context: [
                'first_bad_transaction_id' => $firstBadId,
                'stored_balance_after' => $storedBalance,
                'expected_balance_after' => $expectedBalance,
                'bad_count' => $count,
            ],
        );
    }

    /**
     * unbalanced_group, malformed_transfer and transfer_without_group.
     * Streamed with cursors rather than loaded at once; when $walletIds
     * scopes the audit, the group-level checks still look at every leg of
     * a touched group, not only the legs on the audited wallets.
     *
     * @param  list<int>|null  $walletIds
     * @return array{0: list<Issue>, 1: int}
     */
    private function auditGroups(Connection $connection, string $walletsTable, string $transactionsTable, ?array $walletIds): array
    {
        $transferTypes = [CoreType::TransferOut->value, CoreType::TransferIn->value];
        $issues = [];

        $withoutGroup = $connection->table($transactionsTable.' as t')
            ->join($walletsTable.' as w', 'w.id', '=', 't.wallet_id')
            ->whereIn('t.type', $transferTypes)
            ->whereNull('t.group_uuid')
            ->orderBy('t.id')
            ->select(['t.id as transaction_id', 't.wallet_id', 't.amount', 't.type', 'w.scale', 'w.currency']);

        if ($walletIds !== null) {
            $withoutGroup->whereIn('t.wallet_id', $walletIds);
        }

        foreach ($withoutGroup->cursor() as $row) {
            $issues[] = $this->transferWithoutGroupIssue($row);
        }

        // The group_uuids that make a "transfer group": any group_uuid
        // carried by at least one transfer_out/transfer_in row, scoped to
        // the audited wallets when given. This is a subquery, not a value
        // pulled into PHP, so the next query (and its SUM) stays a single
        // statement regardless of how many groups exist.
        $transferGroupUuids = $connection->table($transactionsTable)
            ->select('group_uuid')
            ->whereIn('type', $transferTypes)
            ->whereNotNull('group_uuid')
            ->distinct();

        if ($walletIds !== null) {
            $transferGroupUuids->whereIn('wallet_id', $walletIds);
        }

        // Joining the full ledger table to that subquery, rather than
        // filtering it by type, is what makes the aggregate sum, and count,
        // EVERY row sharing the group_uuid -- including a fee or reversal
        // leg a later phase might add to it -- instead of only its
        // transfer_out/transfer_in legs.
        $groups = $connection->table($transactionsTable.' as t')
            ->joinSub($transferGroupUuids, 'tg', 'tg.group_uuid', '=', 't.group_uuid')
            ->select('t.group_uuid')
            ->selectRaw(
                'SUM(t.amount) as total, '
                .'SUM(CASE WHEN t.type = ? THEN 1 ELSE 0 END) as out_count, '
                .'SUM(CASE WHEN t.type = ? THEN 1 ELSE 0 END) as in_count, '
                .'COUNT(*) as leg_count, '
                .'MIN(t.wallet_id) as sample_wallet_id',
                [CoreType::TransferOut->value, CoreType::TransferIn->value],
            )
            ->groupBy('t.group_uuid')
            ->orderBy('t.group_uuid');

        $groupsChecked = 0;

        /** @var array<int, array{0: int, 1: string}> $scaleCache */
        $scaleCache = [];

        foreach ($groups->cursor() as $group) {
            $groupsChecked++;

            foreach ($this->groupIssues($connection, $walletsTable, $group, $scaleCache) as $issue) {
                $issues[] = $issue;
            }
        }

        return [$issues, $groupsChecked];
    }

    private function transferWithoutGroupIssue(stdClass $row): Issue
    {
        $walletId = (int) $row->wallet_id;
        $scale = (int) $row->scale;
        $currency = (string) $row->currency;
        $amount = (int) $row->amount;
        $type = (string) $row->type;
        $transactionId = (int) $row->transaction_id;

        return new Issue(
            IssueCode::TransferWithoutGroup,
            walletId: $walletId,
            groupUuid: null,
            transactionId: $transactionId,
            message: sprintf(
                'Ledger row %d on wallet %d is a %s of %s with no group_uuid.',
                $transactionId,
                $walletId,
                $type,
                $this->money($amount, $scale, $currency),
            ),
            context: ['amount' => $amount, 'type' => $type],
        );
    }

    /**
     * @param  array<int, array{0: int, 1: string}>  $scaleCache
     * @return list<Issue>
     */
    private function groupIssues(Connection $connection, string $walletsTable, stdClass $group, array &$scaleCache): array
    {
        $groupUuid = (string) $group->group_uuid;
        $total = (int) $group->total;
        $outCount = (int) $group->out_count;
        $inCount = (int) $group->in_count;
        $legCount = (int) $group->leg_count;
        $sampleWalletId = (int) $group->sample_wallet_id;

        [$scale, $currency] = $this->resolveWalletScale($connection, $walletsTable, $sampleWalletId, $scaleCache);

        $issues = [];

        if ($total !== 0) {
            $issues[] = new Issue(
                IssueCode::UnbalancedGroup,
                walletId: null,
                groupUuid: $groupUuid,
                transactionId: null,
                message: sprintf(
                    'Transfer group %s does not balance to zero: its %d row(s) sum to %s.',
                    $groupUuid,
                    $legCount,
                    $this->money($total, $scale, $currency),
                ),
                context: ['total' => $total, 'leg_count' => $legCount],
            );
        }

        if ($outCount !== 1 || $inCount !== 1) {
            $issues[] = new Issue(
                IssueCode::MalformedTransfer,
                walletId: null,
                groupUuid: $groupUuid,
                transactionId: null,
                message: sprintf(
                    'Transfer group %s has %d transfer_out and %d transfer_in row(s); it needs exactly one of each.',
                    $groupUuid,
                    $outCount,
                    $inCount,
                ),
                context: ['out_count' => $outCount, 'in_count' => $inCount, 'leg_count' => $legCount],
            );
        }

        return $issues;
    }

    /**
     * @param  array<int, array{0: int, 1: string}>  $cache
     * @return array{0: int, 1: string}
     */
    private function resolveWalletScale(Connection $connection, string $walletsTable, int $walletId, array &$cache): array
    {
        if (isset($cache[$walletId])) {
            return $cache[$walletId];
        }

        $row = $connection->table($walletsTable)->where('id', $walletId)->first(['scale', 'currency']);

        $resolved = $row === null ? [2, ''] : [(int) $row->scale, (string) $row->currency];

        $cache[$walletId] = $resolved;

        return $resolved;
    }

    /**
     * $minor formatted with $scale decimals and $currency, falling back to
     * the raw integer when $scale is outside what Amount::format() accepts
     * (invalid_scale exists precisely to report a scale like that).
     */
    private function money(int $minor, int $scale, string $currency): string
    {
        $amount = $scale >= 0 && $scale <= 18 ? Amount::format($minor, $scale) : (string) $minor;

        return trim($amount.' '.$currency);
    }
}
