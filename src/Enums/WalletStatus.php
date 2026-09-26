<?php

declare(strict_types=1);

namespace Anabil\Wallet\Enums;

enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';
}
