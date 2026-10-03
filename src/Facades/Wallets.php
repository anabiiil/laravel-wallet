<?php

declare(strict_types=1);

namespace Anabil\Wallet\Facades;

use Anabil\Wallet\Exceptions\NoAuthenticatedHolder;
use Anabil\Wallet\Exceptions\UnknownGuard;
use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\WalletManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * Static access to the WalletManager singleton: the authenticated holder's
 * wallet, resolved across the guards configured in [wallet.guards], without
 * the caller having to know which guard the request authenticated through.
 *
 * use Anabil\Wallet\Facades\Wallets;
 *
 * $wallet = Wallets::wallet();
 * $balance = Wallets::holder()?->balance();
 *
 * @method static Model|null holder() The authenticated holder from the first configured guard (in order) that has one, or null when none do.
 * @method static string|null guard() The name of the guard holder() resolved the holder from, or null when none did.
 * @method static bool check() Whether an authenticated holder was found on any configured guard.
 * @method static Model holderOrFail() The authenticated holder, like holder(). @throws NoAuthenticatedHolder when none is found.
 * @method static Wallet|null wallet(string|null $name = null) The holder's named wallet (created on first use), or null when there is no authenticated holder.
 * @method static Wallet walletOrFail(string|null $name = null) The holder's named wallet (created on first use). @throws NoAuthenticatedHolder when there is no authenticated holder.
 * @method static Model|null forGuard(string $guard) The authenticated holder on one specific guard, regardless of [wallet.guards]. @throws UnknownGuard when $guard is not defined in [auth.guards].
 *
 * @see WalletManager
 */
class Wallets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WalletManager::class;
    }
}
