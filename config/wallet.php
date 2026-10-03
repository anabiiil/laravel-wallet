<?php

declare(strict_types=1);

use Anabil\Wallet\Models\Wallet;
use Anabil\Wallet\Models\WalletTransaction;

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
    | Default Wallet Name
    |--------------------------------------------------------------------------
    |
    | A holder may own several named wallets. This is the one returned by
    | $user->wallet() when no name is given.
    |
    */

    'default_wallet' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Decimal Places (Scale)
    |--------------------------------------------------------------------------
    |
    | Amounts are passed and read in major units ("250.50"), but stored as
    | integers in minor units (25050). The scale is how many decimal places a
    | wallet uses: 2 or 3. It is copied onto each wallet when it is created
    | and never changes afterwards, so editing these values later only
    | affects wallets created from then on; existing balances keep reading
    | exactly as before.
    |
    | "scale" applies to every currency not listed in "currency_scales".
    |
    */

    'scale' => 2,

    'currency_scales' => [
        'KWD' => 3,
        'BHD' => 3,
        'OMR' => 3,
        'JOD' => 3,
        'TND' => 3,
        'LYD' => 3,
        'IQD' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The Eloquent models used for wallets and ledger entries. You may swap
    | in your own subclasses (to add relations, accessors, scopes...). They
    | must extend the package models, and they inherit every protection:
    | balances and ledger rows still cannot be changed through Eloquent.
    |
    */

    'models' => [
        'wallet' => Wallet::class,
        'transaction' => WalletTransaction::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Types
    |--------------------------------------------------------------------------
    |
    | Set to your own string-backed enum class implementing
    | Anabil\Wallet\Contracts\TransactionType to use your application's
    | transaction types (e.g. App\Enums\WalletType::class). Once set, only
    | its values and the core types are accepted. Leave null to accept any
    | lowercase string such as "cashback" or "fee.card" (at most 32 chars).
    |
    | Its values must not reuse a core type (deposit, withdraw, transfer_in,
    | transfer_out, reversal).
    |
    */

    'types' => null,

    /*
    |--------------------------------------------------------------------------
    | Holder Types
    |--------------------------------------------------------------------------
    |
    | Any model using the HasWallet trait can hold a wallet -- users, admins,
    | employees, merchants. Aliasing them here stores a short, stable key in
    | holder_type instead of a fully qualified class name, so you can move or
    | rename the classes later without rewriting rows.
    |
    | These are merged into Laravel's morph map, so aliases the application
    | already registered are left alone.
    |
    */

    'holders' => [
        // 'user' => App\Models\User::class,
        // 'admin' => App\Models\Admin::class,
        // 'merchant' => App\Models\Merchant::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Guards that Wallets::holder() checks, in order, to find who is
    | currently authenticated. List every guard whose users own wallets
    | (e.g. 'web', 'merchant', 'admin'). The first configured guard with an
    | authenticated user that holds wallets wins. Guards that are not
    | defined in [auth.guards] are skipped rather than erroring, so this
    | list may safely mention a guard the application has not set up yet.
    |
    */

    'guards' => ['web'],

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
