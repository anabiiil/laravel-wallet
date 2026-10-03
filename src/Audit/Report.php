<?php

declare(strict_types=1);

namespace Anabil\Wallet\Audit;

/**
 * The result of one WalletAuditor::audit() run: how many wallets and
 * transfer groups were looked at, and every Issue found along the way.
 */
final readonly class Report
{
    /**
     * @param  list<Issue>  $issues
     */
    public function __construct(
        public int $walletsChecked,
        public int $groupsChecked,
        public array $issues,
    ) {}

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (Issue $issue): bool => $issue->severity() === Severity::Error,
        ));
    }

    /**
     * @return list<Issue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (Issue $issue): bool => $issue->severity() === Severity::Warning,
        ));
    }

    /**
     * Whether the ledger is clean: no errors. Warnings alone still pass.
     */
    public function passed(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wallets_checked' => $this->walletsChecked,
            'groups_checked' => $this->groupsChecked,
            'passed' => $this->passed(),
            'error_count' => count($this->errors()),
            'warning_count' => count($this->warnings()),
            'issues' => array_map(fn (Issue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
