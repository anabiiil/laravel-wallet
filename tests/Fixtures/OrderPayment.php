<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Anabil\Wallet\Contracts\WalletOperation;
use BackedEnum;

final class OrderPayment implements WalletOperation
{
    public function __construct(
        private readonly Order $order,
        private readonly string|BackedEnum $type = AppType::Purchase,
    ) {}

    public function type(): string|BackedEnum
    {
        return $this->type;
    }

    public function reference(): Order
    {
        return $this->order;
    }

    public function description(): string
    {
        return 'Payment for order #'.$this->order->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['order_id' => $this->order->id];
    }
}
