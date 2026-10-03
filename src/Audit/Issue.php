<?php

declare(strict_types=1);

namespace Anabil\Wallet\Audit;

/**
 * One problem WalletAuditor found. $walletId, $groupUuid and $transactionId
 * identify where the problem lives (a check only ever fills in the ones
 * that apply to it: a per-wallet check fills $walletId, a per-group check
 * fills $groupUuid, and so on). $message is meant for a human, with decimal
 * amounts already formatted with the relevant wallet's scale and currency;
 * $context carries the raw minor-unit numbers the message was built from,
 * for anything that wants to act on them programmatically.
 */
final readonly class Issue
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public IssueCode $code,
        public ?int $walletId,
        public ?string $groupUuid,
        public ?int $transactionId,
        public string $message,
        public array $context = [],
    ) {}

    public function severity(): Severity
    {
        return $this->code->severity();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity()->value,
            'wallet_id' => $this->walletId,
            'group_uuid' => $this->groupUuid,
            'transaction_id' => $this->transactionId,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
