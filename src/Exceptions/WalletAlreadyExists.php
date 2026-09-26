<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class WalletAlreadyExists extends WalletException
{
    public static function for(Model $holder, string $name, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Holder [%s:%s] already has a wallet named [%s]. Use $holder->wallet(\'%s\') to get it.',
            $holder->getMorphClass(),
            (string) $holder->getKey(),
            $name,
            $name,
        ), 0, $previous);
    }
}
