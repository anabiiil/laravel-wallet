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

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Key Types
    |--------------------------------------------------------------------------
    |
    | The key type of the models on the other side of each polymorphic
    | relation: "holder" (who owns a wallet), "reference" (what a transaction
    | is for), and "performed_by" (who triggered a transaction). Use "int"
    | for models with auto-incrementing ids, or "uuid" / "ulid" for models
    | using the HasUuids / HasUlids traits. Read at migration time.
    |
    */

    'morph_keys' => [
        'holder' => 'int',        // int | uuid | ulid
        'reference' => 'int',
        'performed_by' => 'int',
    ],

];
