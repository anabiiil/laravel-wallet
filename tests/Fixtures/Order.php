<?php

declare(strict_types=1);

namespace Anabil\Wallet\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A plain model used as a transaction reference.
 *
 * @property int $id
 */
class Order extends Model
{
    protected $table = 'orders';
}
