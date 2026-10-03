<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An authenticatable model that does NOT use HasWallet, so WalletManager
 * must skip it like any other guard with no holder. Reuses the "admins"
 * table: nothing here reads its columns beyond id/name/timestamps.
 */
class Guest extends Authenticatable
{
    protected $table = 'admins';

    protected $fillable = ['name'];
}
