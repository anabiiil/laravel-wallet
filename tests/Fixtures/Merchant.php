<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Anabil\Wallet\Concerns\HasWallet;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Merchant extends Authenticatable
{
    use HasWallet;

    protected $table = 'merchants';

    protected $fillable = ['name'];

    public function walletMinBalance(): int|float|string
    {
        return -100;
    }
}
