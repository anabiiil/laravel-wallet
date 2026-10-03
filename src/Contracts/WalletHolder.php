<?php

declare(strict_types=1);

namespace Anabil\Wallet\Contracts;

use Anabil\Wallet\Models\Wallet;

/**
 * Describes the subset of HasWallet's API that WalletManager calls on a
 * holder. It exists only for PHPStan: HasWallet is a trait, so its
 * "require extends Model" relationship cannot be intersected into a type
 * the way an interface can, and there would otherwise be no way to tell
 * the type checker that a holder narrowed by WalletManager::isHolder() has
 * wallet(). Nothing needs to actually implement it: the assertion on
 * WalletManager::isHolder() is a type guard PHPStan takes on trust, the same way it
 * would for any other custom assertion function.
 *
 * @internal
 */
interface WalletHolder
{
    public function wallet(?string $name = null): Wallet;
}
