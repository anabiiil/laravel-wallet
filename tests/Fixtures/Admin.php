<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Anabil\Wallet\Concerns\HasWallet;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    use HasWallet;

    protected $table = 'admins';

    protected $fillable = ['name'];

    public function walletCurrency(): string
    {
        return 'USD';
    }
}
