<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 code used when a wallet is created without an explicit currency.
    |
    */

    'default_currency' => env('WALLET_DEFAULT_CURRENCY', 'EGP'),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'wallets' => 'wallets',
        'transactions' => 'wallet_transactions',
    ],

];
