<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

/**
 * Thrown by WalletManager::forGuard() when asked for a guard that is not
 * defined in [auth.guards]. Unlike the guards checked automatically via
 * [wallet.guards] (which silently skips undefined ones), forGuard() names a
 * specific guard, so an undefined one is a caller mistake worth surfacing.
 */
class UnknownGuard extends WalletException
{
    public static function undefined(string $guard): self
    {
        return new self(sprintf(
            'Guard [%s] is not defined in [auth.guards]. Define it there, or check [wallet.guards] for the guards resolved automatically.',
            $guard,
        ));
    }
}
