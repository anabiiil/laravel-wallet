# Laravel Wallet

Race-safe wallets, balances and an immutable ledger for Laravel — exact decimal amounts, multi-guard holders, transfers, reversals and idempotent writes.

[![Tests](https://github.com/anabiiil/laravel-wallet/actions/workflows/tests.yml/badge.svg)](https://github.com/anabiiil/laravel-wallet/actions/workflows/tests.yml)

## Why this package

- **Exact decimal amounts.** Balances are stored as integers in minor units, but every public method takes and returns decimal strings (`"250.50"`). Nothing is ever rounded: an amount that doesn't fit the wallet's scale is refused, not truncated.
- **No overdraw, ever.** Every balance change is one guarded, single-statement `UPDATE` whose `WHERE` clause re-checks the minimum balance and the wallet's status against the committed row. Two concurrent withdrawals can never both succeed past the floor.
- **An immutable ledger.** Ledger rows can never be created, updated or deleted through Eloquent. The only way to correct a mistake is to append a reversing entry.
- **Idempotency keys.** Give a deposit, withdrawal, transfer or reversal a key and a retry (a redelivered webhook, a resubmitted form) replays the original result instead of double-processing.
- **Deadlock-free transfers.** Two wallets always have their row locks taken in the same order, whichever direction the money moves, so two transfers between the same pair of wallets can never deadlock each other.
- **Reversals, not edits.** Undo a deposit, withdrawal or a whole transfer with a linked, opposite entry. Chargebacks can force a reversal through even on a frozen wallet.
- **Freeze and close.** Stop money leaving a wallet, or shut it down for good once it's empty — enforced by the same guarded `UPDATE`, not by application code remembering to check a flag.
- **Any holder, any guard.** Put `HasWallet` on any Eloquent model — users, merchants, admins — and resolve "whoever is logged in" across several auth guards with one call.
- **An audit command.** `wallet:check` proves the ledger's invariants still hold, for everything that could only have gone wrong outside the package (raw SQL, a manual edit, a bug).

## Requirements

- PHP ^8.2
- Laravel (illuminate/*) ^12.0
- MySQL, PostgreSQL, or SQLite

## Installation

```bash
composer require anabil/laravel-wallet
```

Publish and run the migrations (creates `wallets` and `wallet_transactions`):

```bash
php artisan vendor:publish --tag=wallet-migrations
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=wallet-config
```

## Configuration

Everything lives in `config/wallet.php`.

| Key | Default | Meaning |
|---|---|---|
| `default_currency` | `env('WALLET_DEFAULT_CURRENCY', 'EGP')` | ISO 4217 code used when a wallet is created without an explicit currency. |
| `default_wallet` | `'default'` | The wallet name `$holder->wallet()` returns when no name is given. |
| `scale` | `2` | Decimal places (2 or 3) for any currency not listed in `currency_scales`. |
| `currency_scales` | `KWD`, `BHD`, `OMR`, `JOD`, `TND`, `LYD`, `IQD` → `3` | Per-currency overrides of `scale`. |
| `models` | `Wallet::class`, `WalletTransaction::class` | Your own subclasses must extend these; every protection (immutability, guarded writes) is inherited automatically. |
| `types` | `null` | A string-backed enum implementing `TransactionType` for your app's own transaction types. `null` accepts any lowercase string (see [Transaction types](#transaction-types)). |
| `holders` | `[]` | Short aliases for holder classes (`'user' => App\Models\User::class`), merged into Laravel's morph map. |
| `guards` | `['web']` | Guards `Wallets::holder()` checks, in order, to find the authenticated holder. |
| `tables` | `wallets`, `wallet_transactions` | Table names. |
| `morph_keys` | `holder`/`reference`/`performed_by` → `int` | Key type (`int`, `uuid`, `ulid`) of the model on the other side of each polymorphic relation. |

`scale` and `currency_scales` are only read **when a wallet is created** — they're baked into the wallet's `scale` column and never re-read afterwards, so changing them later only affects wallets created from then on.

`morph_keys` is only read **at migration time** (it decides the column type of `*_id`), so set it correctly before running `php artisan migrate` for the first time; changing it afterwards needs its own migration.

## Setting up holders

Add `HasWallet` to any Eloquent model that should be able to own a wallet — a user, a merchant, an admin, anything:

```php
use Anabil\Wallet\Concerns\HasWallet;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasWallet;
}
```

Alias holder classes in `config/wallet.php` so the stored `holder_type` is a short, stable string instead of a fully-qualified class name:

```php
'holders' => [
    'user' => App\Models\User::class,
    'merchant' => App\Models\Merchant::class,
],
```

Override the per-holder defaults used when a wallet is created implicitly:

```php
class Merchant extends Authenticatable
{
    use HasWallet;

    public function walletCurrency(): string
    {
        return 'USD';
    }

    public function walletMinBalance(): int|float|string
    {
        return -100; // a credit line of 100.00
    }
}
```

## Wallets

Every holder has a default wallet, created the first time it's touched, plus any number of named ones:

```php
$wallet = $user->wallet();          // the default wallet, created on first use
$savings = $user->wallet('savings'); // a named wallet, created on first use

$user->hasWallet('savings'); // false until wallet('savings') or createWallet() runs

$wallet = $user->createWallet('gift-cards', currency: 'USD', minBalance: 0, meta: ['source' => 'promo']);
```

`balance()` never creates a wallet — it reads `"0.00"` (formatted with the holder's default currency's scale) for one that doesn't exist yet:

```php
$user->balance();           // "0.00" — no wallet created
$user->wallet()->deposit(250);
$user->balance();           // "250.00"
```

A wallet's scale (2 or 3 decimal places) is fixed from the config **at creation** and never changes afterwards, even if the config does later.

## Amounts

Every amount you pass in is major units — an `int`, a `float`, or a decimal string (`250`, `100.5`, `"100.50"`) — and everything you read back is a decimal string with exactly the wallet's scale (`"250.50"`).

Nothing is ever rounded:

```php
$wallet->deposit('100.501'); // throws InvalidAmount (too precise for a 2-decimal wallet)
$wallet->deposit('abc');     // throws InvalidAmount (malformed)
$wallet->deposit(NAN);       // throws InvalidAmount (not finite)
$wallet->deposit(1.0e20);    // throws InvalidAmount (too large to hold exactly)

$wallet->deposit(0.1 + 0.2); // throws InvalidAmount: 0.1 + 0.2 is 0.30000000000000004,
                              // which has more than 2 decimal places
```

Raw minor units are available when you need them. Say the wallet's balance is `"250.50"`:

```php
$wallet->balanceInMinor();    // 25050
$wallet->minBalanceInMinor(); // 0
$wallet->formatted();         // "250.50 EGP"
$wallet->toDecimal(3000);     // "30.00" — formats any minor-unit integer with this wallet's scale

$tx = $wallet->withdraw(30);

$tx->amountInMinor();         // -3000
$tx->balanceAfterInMinor();   // 22050
```

Query-level aggregates bypass the model's accessor and return **minor units**, not decimal strings:

```php
WalletTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'); // 25050, an int — not "250.50"
```

## Deposits & withdrawals

```php
$tx = $wallet->deposit(
    amount: 250,
    type: 'topup',               // optional; defaults to CoreType::Deposit / CoreType::Withdraw
    reference: $invoice,          // any saved Eloquent model
    description: 'Top-up via card',
    meta: ['provider' => 'stripe'],
    performedBy: $admin,          // who/what triggered it
    idempotencyKey: 'evt_123',    // see Idempotency below
);

$wallet->withdraw(50, 'fee', description: 'Monthly fee');
```

A withdrawal that would take the balance below the wallet's minimum balance throws `InsufficientFunds`, which carries the numbers you need to show the user:

```php
try {
    $wallet->withdraw(500);
} catch (\Anabil\Wallet\Exceptions\InsufficientFunds $e) {
    $e->wallet;      // the Wallet
    $e->requested;   // "500.00"
    $e->balance;     // "100.00"
    $e->minBalance;  // "0.00"
    $e->available;   // "100.00"
}
```

A deposit never checks the floor — a wallet already below its minimum balance (for example after its credit line was reduced) can still receive money. `spendable()` and `canWithdraw()` are read from the instance and are advisory only: the real check happens against the database row at the moment you call `withdraw()`.

```php
$wallet->spendable();        // "60.00" — balance minus min_balance, as a decimal string
$wallet->canWithdraw(60);    // true
$wallet->canWithdraw(60.01); // false
```

## Transaction types

`CoreType` is the package's own enum: `Deposit`, `Withdraw`, `TransferIn`, `TransferOut`, `Reversal`. The last three are **reserved** — `deposit()` / `withdraw()` refuse them (`InvalidTransaction`); only the package itself writes them, for transfers and reversals.

With `wallet.types` left at `null`, `deposit()` / `withdraw()` accept any lowercase string type (`/^[a-z][a-z0-9_.:-]{0,31}$/`, at most 32 characters). To use your own typed enum instead, implement `TransactionType` and point the config at it:

```php
use Anabil\Wallet\Contracts\TransactionType;

enum AppType: string implements TransactionType
{
    case Purchase = 'purchase';
    case Cashback = 'cashback';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Cashback => 'Cashback',
        };
    }
}
```

```php
// config/wallet.php
'types' => App\Enums\AppType::class,
```

Once configured, only `CoreType` and your enum's cases are accepted. Reading an entry's `type` gives you back a `CoreType` case, a case of your configured enum, or the raw string when neither recognises the stored value:

```php
$tx = $wallet->deposit(10, AppType::Cashback);

$tx->type;          // AppType::Cashback
$tx->type->label(); // "Cashback"
```

## WalletOperation classes

Bundle a recurring type, reference, description and meta into a single reusable object instead of repeating them at every call site:

```php
use Anabil\Wallet\Contracts\WalletOperation;
use BackedEnum;

final class OrderPayment implements WalletOperation
{
    public function __construct(private readonly Order $order) {}

    public function type(): string|BackedEnum
    {
        return AppType::Purchase;
    }

    public function reference(): Order
    {
        return $this->order;
    }

    public function description(): string
    {
        return 'Payment for order #'.$this->order->id;
    }

    public function meta(): array
    {
        return ['order_id' => $this->order->id];
    }
}
```

```php
$wallet->withdraw(99.99, new OrderPayment($order));
```

When `$type` is a `WalletOperation`, don't also pass `reference`, `description` or `meta` — the operation already supplies them (`InvalidTransaction` if you do). `performedBy` is call-site context and may still be passed alongside it.

## Querying the ledger

`$wallet->transactions()` and `WalletTransaction::query()` both carry the same query helpers:

```php
$wallet->transactions()->credits()->count();          // entries that added money
$wallet->transactions()->debits()->get();              // entries that took money out
$wallet->transactions()->ofType(CoreType::Deposit, AppType::Cashback)->get();
$wallet->transactions()->whereReference($order)->get();
$wallet->transactions()->wherePerformedBy($admin)->get();
$wallet->transactions()->latestFirst()->first();       // newest first, by id

$wallet->transactions()->transfers()->get();            // transfer_in and transfer_out entries
$wallet->transactions()->reversals()->get();             // type = reversal
$wallet->transactions()->whereReversed()->get();         // entries that have a reversal
$wallet->transactions()->whereNotReversed()->get();
```

Relations on an entry:

```php
$tx->wallet;      // BelongsTo Wallet
$tx->reference;   // MorphTo — the model it's about (an order, an invoice...)
$tx->performedBy; // MorphTo — who/what triggered it
$tx->reverses;    // BelongsTo WalletTransaction — the entry this one undoes
$tx->reversal;    // HasOne WalletTransaction — the entry that undoes this one, if any

$tx->isCredit();   // amount > 0
$tx->isDebit();    // amount < 0
$tx->isReversal(); // type === CoreType::Reversal
$tx->isReversed(); // has a loaded reversal relation

$tx->counterpart(); // the other leg: transfer_in <-> transfer_out, or the paired reversal
```

## Idempotency

Pass `idempotencyKey` to `deposit()`, `withdraw()`, `transferTo()` or `reverse()` and a retry with the **same key on the same wallet** returns the entry recorded the first time instead of writing again:

```php
// A payment webhook may be redelivered; the event id makes retries safe.
$tx = $wallet->deposit(49.99, 'topup', reference: $invoice, idempotencyKey: 'stripe_evt_'.$event->id);

$tx->wasRecentlyCreated; // true the first time, false on every replay
```

Reusing a key for a **different** operation (a different amount, direction, type, reference, actor, description, meta, or transfer recipient) throws `IdempotencyConflict` instead of silently doing the wrong thing. A failed attempt (say, `InsufficientFunds`) never consumes the key — retry it once the balance allows.

The key is scoped **per wallet**: the same key on two different wallets writes twice, each idempotently. For a transfer, the key lives on the sender's entry only — two different senders may reuse the same key towards the same recipient.

## Transfers

Move money between any two wallets, or between any two holders directly:

```php
$result = $user->wallet()->transferTo(
    $merchant->wallet(),
    amount: 100.50,
    reference: $order,
    description: 'Paid for order #'.$order->id,            // the sender's entry
    recipientDescription: 'Payment from order #'.$order->id, // defaults to $description
);

// or straight from one holder to another's default wallet:
$user->transferTo($merchant, 100.50, reference: $order);

$result->out;        // the sender's transfer_out entry (negative amount)
$result->in;         // the recipient's transfer_in entry (positive amount)
$result->amount();   // "100.50"
$result->group;      // a uuid shared by both entries
$result->replayed();  // true only if this returned an earlier, idempotent write
```

Both wallets must share the same currency (and therefore the same scale) and the same database connection, or `InvalidTransfer` is thrown before anything is written. Both legs are written in one database transaction — either both entries land or neither does — and the row locks of a posting always take effect in ascending wallet id order, whichever direction the money moves, so two transfers between the same pair of wallets can never deadlock each other.

## Reversals

Undo a deposit, a withdrawal, or an entire transfer with a new, linked entry of the opposite amount:

```php
$deposit = $wallet->deposit(49.99, reference: $order);

// later: the charge needs to be undone
$refund = $deposit->reverse(description: 'Refund: item returned');

$refund->amount;          // "-49.99"
$refund->reverses;        // the original deposit
$deposit->reversal;       // the refund entry
$refund->reference;       // the order — copied from the original entry
```

Reversing one leg of a transfer reverses the **whole transfer** atomically — both legs, sharing a new group uuid — and returns the reversal of the leg you called it on. A reversal's reference is always copied from the entry it reverses, so the entries tied to a reference still net to zero.

The debit side of a reversal respects the wallet's minimum balance and needs an active wallet, just like a withdrawal — unless you pass `force: true` (a chargeback after the money has already been spent):

```php
$chargeback = $payment->reverse(description: 'Chargeback', force: true);
```

`force` skips the floor check and lets the debit through even on a frozen wallet; it never bypasses the overflow guard, and never applies to a closed wallet.

There is no partial reversal — record a partial refund as a normal entry instead (a deposit with your own refund type and the order as reference). An entry can be reversed at most once (enforced by a unique index on `reverses_id`, not merely by an application-level check), and a reversal itself can never be reversed — `InvalidReversal` either way.

## Wallet status

A wallet is born `active`. `freeze()`, `unfreeze()` and `close()` change it, enforced by the same guarded `UPDATE` that moves money — never by a possibly-stale instance:

| Status | Credits in | Debits out |
|---|---|---|
| `active` | allowed | allowed (subject to the minimum balance) |
| `frozen` | allowed | refused (`WalletFrozen`) — except a forced reversal (chargeback) |
| `closed` | refused (`WalletClosed`) | refused (`WalletClosed`), even a forced reversal |

```php
$wallet->freeze('Suspicious activity', performedBy: $admin);
$wallet->unfreeze('Cleared', performedBy: $admin);
$wallet->close('Account closed');   // only an empty wallet (balance exactly 0) can close

$wallet->isActive();
$wallet->isFrozen();
$wallet->isClosed();
```

Closing is **terminal** — a closed wallet can never be reopened or deleted. `freeze()`/`unfreeze()`/`close()` are no-ops (no write, no event) when the wallet already has the requested status. Every successful change dispatches `WalletStatusChanged` after commit.

Query scopes:

```php
Wallet::query()->active()->get();
Wallet::query()->frozen()->get();
$user->wallets()->closed()->get();
```

## Authenticated holder across guards

`Wallets` (the `WalletManager` facade) finds "whoever is logged in" without the caller having to know which guard authenticated the request, checking the guards listed in `wallet.guards`, in order:

```php
use Anabil\Wallet\Facades\Wallets;

Wallets::holder();       // the authenticated holder (Model&HasWallet), or null
Wallets::guard();        // which guard it came from ('web', 'merchant'...), or null
Wallets::check();        // bool
Wallets::holderOrFail();  // throws NoAuthenticatedHolder listing every guard checked
Wallets::wallet();        // the holder's default wallet, created on first use, or null
Wallets::walletOrFail();
Wallets::forGuard('admin'); // one specific guard, regardless of [wallet.guards];
                             // throws UnknownGuard if 'admin' isn't defined in [auth.guards]
```

## Events

All of these implement `ShouldDispatchAfterCommit`: a listener only runs once the outermost database transaction commits, and never if it rolls back or the write replays an idempotency key.

| Event | Fired |
|---|---|
| `TransactionRecorded` | Once per ledger entry written (a deposit/withdrawal is one event, a transfer two). Never on replay. |
| `TransactionReversed` | Once per entry reversed (twice for a transfer: once per leg). Never on replay. |
| `TransferCompleted` | Once per transfer. Never on replay. |
| `WalletStatusChanged` | Once per status change that actually happened. Never for a no-op call. |

## Exceptions

Every exception extends `Anabil\Wallet\Exceptions\WalletException` (itself a `RuntimeException`), so you can catch everything the package throws with one type.

| Exception | Thrown when |
|---|---|
| `InvalidAmount` | An amount is zero, negative where positive is required, malformed, not finite, too precise for the wallet's scale, or too large to store exactly. |
| `InsufficientFunds` | A debit would take the balance below the minimum balance. |
| `BalanceOverflow` | A credit would overflow a 64-bit integer, or a forced debit would go below the smallest storable value. |
| `WalletFrozen` | A debit (withdrawal, outgoing transfer, or non-forced reversal) hits a frozen wallet. |
| `WalletClosed` | Any movement, in either direction, hits a closed wallet. |
| `InvalidTransaction` | The type, description, meta, idempotency key, or an unsaved reference/actor model are invalid; or a reserved type was used directly. |
| `InvalidTransfer` | The sender and recipient are the same wallet, or differ in currency, scale, or database connection; or the recipient model doesn't use `HasWallet`. |
| `InvalidReversal` | The entry is unsaved, is itself a reversal, is already reversed, or is a transfer leg whose counterpart is missing. |
| `IdempotencyConflict` | A key was reused on the same wallet for a different operation. |
| `ImmutableWallet` | Code tried to change a protected wallet attribute, delete a wallet, or bulk-write wallets through a query. |
| `ImmutableTransaction` | Code tried to create, update, delete, or bulk-write a ledger entry through Eloquent. |
| `InvalidWalletAttribute` | A wallet's name, currency, or scale is invalid, or its `scale` column wasn't loaded when needed. |
| `InvalidStatusTransition` | A status change is invalid: the wallet is closed, a close was attempted on a non-zero balance, or the reason/actor given are invalid. |
| `WalletAlreadyExists` | `createWallet()` is called with a name the holder already has. |
| `NoAuthenticatedHolder` | `holderOrFail()` / `walletOrFail()` find no authenticated holder on any configured guard. |
| `UnknownGuard` | `forGuard()` is asked for a guard not defined in `[auth.guards]`. |
| `InvalidConfiguration` | `wallet.types`, `wallet.scale`, `wallet.currency_scales`, or `wallet.guards` are misconfigured. |

## Auditing

`wallet:check` proves the ledger's invariants still hold — things that can only be true through the package's own writes, so a violation means something wrote outside it (raw SQL, a manual edit, a bug):

```bash
php artisan wallet:check                        # every wallet
php artisan wallet:check --wallet=5 --wallet=9  # only these wallets (and the transfer groups touching them)
php artisan wallet:check --chunk=1000           # wallets per batch (default 500)
php artisan wallet:check --json                 # machine-readable report
```

Human output:

```
Checked 42 wallet(s) and 7 group(s): 0 error(s), 0 warning(s).
```

Issue codes:

| Code | Severity | Means |
|---|---|---|
| `balance_mismatch` | error | `wallets.balance` doesn't equal the sum of that wallet's ledger rows. |
| `last_balance_after_mismatch` | error | The wallet's `balance` doesn't equal the `balance_after` of its latest ledger row. |
| `broken_chain` | error | Some ledger row's `balance_after` doesn't equal the running sum of amounts up to and including it. |
| `below_floor` | **warning** | The balance is below `min_balance` — expected once a credit line is reduced after money was already spent. |
| `invalid_scale` | error | The wallet's `scale` is neither 2 nor 3. |
| `invalid_status` | error | The wallet's `status` isn't a valid `WalletStatus` value. |
| `closed_with_balance` | error | The wallet is closed but its balance isn't zero. |
| `unbalanced_group` | error | A transfer's (or a transfer reversal's) legs don't sum to zero. |
| `malformed_transfer` | error | A transfer group doesn't have exactly one `transfer_out` and one `transfer_in` row. |
| `transfer_without_group` | error | A `transfer_out`/`transfer_in` row has no `group_uuid`. |
| `invalid_reversal` | error | A row's `reverses_id` doesn't actually point at an entry it correctly undoes. |
| `reversal_without_link` | error | A `reversal`-type row has no `reverses_id`. |

Only `below_floor` is a warning; every other code fails `$report->passed()`. Exit codes: `0` clean (warnings allowed), `1` at least one error, `2` invalid command options.

JSON output (`--json`) is `Report::toArray()`:

```json
{
    "wallets_checked": 42,
    "groups_checked": 7,
    "passed": true,
    "error_count": 0,
    "warning_count": 0,
    "issues": []
}
```

Run it programmatically:

```php
use Anabil\Wallet\Audit\WalletAuditor;

$report = app(WalletAuditor::class)->audit(walletIds: null, chunk: 500);

$report->passed();     // no errors (warnings still pass)
$report->errors();     // list<Issue>
$report->warnings();   // list<Issue>

foreach ($report->issues as $issue) {
    $issue->code;         // IssueCode
    $issue->severity();   // Severity::Error | Severity::Warning
    $issue->message;      // human-readable, amounts already formatted
    $issue->context;      // raw minor-unit numbers, for programmatic use
}
```

There is deliberately **no `--fix`**: a mismatch like this only exists because something wrote to the tables outside the package, and which side of the mismatch is "correct" isn't something the data can answer on its own. Guessing wrong would corrupt the ledger further. Fix it by hand, as its own auditable ledger entry (a deposit, withdrawal, or reversal describing what happened) — not an unattended `UPDATE`.

Schedule it so problems surface on their own:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('wallet:check')->daily()->emailOutputOnFailure('ops@example.com');
```

## Immutability & guarantees

Blocked through Eloquent, whatever path is used (including `saveQuietly()`, `createQuietly()` and `Model::withoutEvents()`):

- Changing a wallet's `balance`, `holder_type`/`holder_id`, `name`, `currency`, `scale`, or `status` directly.
- Deleting a wallet.
- Any bulk write to wallets through a query builder: `insert()`, `upsert()`, `insertOrIgnore()`, `update()`/`increment()`/`decrement()` on a protected column or on `min_balance`, `delete()`, `truncate()`.
- Creating, updating, or deleting a `WalletTransaction` through Eloquent, in any form, including every bulk-write method on its query builder.

Not blocked — **by design**: raw SQL and `DB::table()` writes. The package's own internals use exactly that path (the query builders refuse writes only at the Eloquent layer), and `wallet:check` exists specifically to catch anything that bypasses the model this way.

### How it stays correct under load

Every balance change is one guarded `UPDATE`, never a read-then-write:

```sql
-- a withdrawal (debit, floor enforced)
UPDATE wallets
SET balance = balance - :amount, updated_at = :now
WHERE id = :id
  AND status IN ('active')
  AND balance + (-:amount) >= min_balance

-- a forced reversal's debit (no floor, frozen wallets allowed)
UPDATE wallets
SET balance = balance - :amount, updated_at = :now
WHERE id = :id
  AND status IN ('active', 'frozen')
  AND balance >= :smallest_storable_value

-- a deposit or transfer_in (credit)
UPDATE wallets
SET balance = balance + :amount, updated_at = :now
WHERE id = :id
  AND status IN ('active', 'frozen')
  AND balance <= :largest_storable_value
```

If zero rows match, nothing happened — the row is re-read to work out exactly why (closed, frozen, below floor, would overflow) and the matching exception is thrown. Because the `WHERE` clause is evaluated by the database against the row as it exists at that moment, and the `UPDATE` holds the row's lock until commit, two concurrent debits against the same wallet are always serialized: the second one re-checks the floor against the balance the first one just committed, never against a stale value read earlier in PHP.

Status changes go through the same mechanism:

```sql
UPDATE wallets
SET status = :to, updated_at = :now
WHERE id = :id
  AND status = :from
  -- and, only when closing:
  AND balance = 0
```

A transfer's two `UPDATE`s run inside one database transaction, in ascending wallet id order regardless of which way the money moves — so a transfer A→B and a transfer B→A always lock the two rows in the same order and can never deadlock each other.

## Testing

```bash
composer test      # vendor/bin/pest
composer lint       # vendor/bin/pint --test
composer analyse    # vendor/bin/phpstan analyse --memory-limit=1G
```

The suite runs against SQLite, MySQL and PostgreSQL (set `DB_CONNECTION`, `DB_HOST`, etc.) and includes a `concurrency` group that forks real child processes with `pcntl_fork()` to exercise the guarded updates under genuine concurrent load — it needs the `pcntl` extension and is skipped automatically when that isn't available.

## Roadmap

Not implemented yet:

- Holds / authorizations (reserve funds without moving them)
- Fees attached to a transfer or transaction
- Per-wallet or per-holder spending limits

## License

MIT.
