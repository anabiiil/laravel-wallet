<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

/**
 * Thrown by WalletManager::holderOrFail() / walletOrFail() when none of the
 * configured guards has an authenticated wallet holder.
 */
class NoAuthenticatedHolder extends WalletException
{
    /**
     * @param  list<string>  $guards  the guards that were checked, in order
     */
    public static function acrossGuards(array $guards): self
    {
        return new self(sprintf(
            'No authenticated wallet holder was found on any of the configured guards: [%s].',
            $guards === [] ? '(none configured)' : implode(', ', $guards),
        ));
    }
}
