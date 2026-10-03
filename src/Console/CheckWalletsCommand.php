<?php

declare(strict_types=1);

namespace Anabil\Wallet\Console;

use Anabil\Wallet\Audit\Issue;
use Anabil\Wallet\Audit\Report;
use Anabil\Wallet\Audit\Severity;
use Anabil\Wallet\Audit\WalletAuditor;
use Illuminate\Console\Command;

/**
 * Runs WalletAuditor and reports what it finds.
 *
 * There is deliberately no --fix option. A mismatch this audit can find only
 * exists because something wrote to the tables outside the package (raw
 * SQL, a manual database edit, or a bug): which side of the mismatch is
 * correct is not something the data itself can answer, and guessing wrong
 * would silently corrupt the ledger further. Fixing it is a decision for a
 * human, and the correction belongs in the ledger as its own auditable
 * entry (a deposit, withdrawal or reversal with a description of what
 * happened), not as a raw UPDATE this command would run unattended.
 */
class CheckWalletsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wallet:check
        {--wallet=* : Only these wallet ids}
        {--chunk=500 : How many wallets to check per batch}
        {--json : Print the report as JSON instead of human-readable text}';

    /**
     * @var string
     */
    protected $description = 'Audit the wallet ledger for invariant violations';

    public function __construct(private readonly WalletAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunk = $this->parsedChunk();

        if ($chunk === null) {
            $this->components->error(sprintf(
                'The --chunk option must be a positive integer, got [%s].',
                $this->displayValue($this->option('chunk')),
            ));

            return 2;
        }

        $walletIds = $this->parsedWalletIds();

        if ($walletIds === false) {
            $this->components->error(sprintf(
                'The --wallet option must list positive integers, got [%s].',
                implode(', ', $this->walletOptionValues()),
            ));

            return 2;
        }

        $report = $this->auditor->audit($walletIds, $chunk);

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT));
        } else {
            $this->renderHuman($report);
        }

        return $report->passed() ? 0 : 1;
    }

    private function renderHuman(Report $report): void
    {
        foreach ($report->issues as $issue) {
            $line = $this->issueLine($issue);

            match ($issue->severity()) {
                Severity::Error => $this->components->error($line),
                Severity::Warning => $this->components->warn($line),
            };
        }

        $this->components->info(sprintf(
            'Checked %d wallet(s) and %d group(s): %d error(s), %d warning(s).',
            $report->walletsChecked,
            $report->groupsChecked,
            count($report->errors()),
            count($report->warnings()),
        ));
    }

    private function issueLine(Issue $issue): string
    {
        $location = array_filter([
            $issue->walletId !== null ? "wallet {$issue->walletId}" : null,
            $issue->groupUuid !== null ? "group {$issue->groupUuid}" : null,
            $issue->transactionId !== null ? "transaction {$issue->transactionId}" : null,
        ]);

        return sprintf(
            '[%s]%s %s',
            $issue->code->value,
            $location === [] ? '' : ' ('.implode(', ', $location).')',
            $issue->message,
        );
    }

    /**
     * The validated --chunk option, or null when it is not a positive
     * integer.
     */
    private function parsedChunk(): ?int
    {
        $value = $this->option('chunk');
        $value = is_array($value) ? null : (string) $value;

        if ($value === null || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The validated --wallet ids, null when none were given (meaning every
     * wallet), or false when at least one value is not a positive integer.
     *
     * @return list<int>|false|null
     */
    private function parsedWalletIds(): array|false|null
    {
        $values = $this->walletOptionValues();

        if ($values === []) {
            return null;
        }

        $ids = [];

        foreach ($values as $value) {
            if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
                return false;
            }

            $ids[] = (int) $value;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function walletOptionValues(): array
    {
        $values = $this->option('wallet');

        return is_array($values) ? array_values(array_map(static fn (mixed $v): string => (string) $v, $values)) : [];
    }

    /**
     * A scalar option value (never an array for --chunk) rendered for an
     * error message.
     */
    private function displayValue(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(static fn (mixed $v): string => (string) $v, $value)) : (string) $value;
    }
}
