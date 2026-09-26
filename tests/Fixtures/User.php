<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Anabil\Wallet\Concerns\HasWallet;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasWallet;

    protected $table = 'users';

    protected $fillable = ['name'];
}
