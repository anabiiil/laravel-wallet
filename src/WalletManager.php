<?php

declare(strict_types=1);

namespace Anabil\Wallet;

use Anabil\Wallet\Concerns\HasWallet;
use Anabil\Wallet\Contracts\WalletHolder;
use Anabil\Wallet\Exceptions\InvalidConfiguration;
use Anabil\Wallet\Exceptions\NoAuthenticatedHolder;
use Anabil\Wallet\Exceptions\UnknownGuard;
use Anabil\Wallet\Models\Wallet;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the authenticated wallet holder without the caller having to know
 * which guard the request authenticated through. Checks the guards listed
 * in [wallet.guards], in order, skipping any guard that is not defined in
 * [auth.guards] and any authenticated user that does not use HasWallet.
 *
 * Every method re-reads the config and asks the auth factory for the
 * current user: nothing is cached on this instance, so a single, shared
 * (singleton) instance stays correct across requests under Octane, and a
 * logout between two calls is reflected immediately.
 */
final class WalletManager
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * The authenticated holder from the first configured guard (in order)
     * that has one, or null when none do.
     *
     * @return (Model&WalletHolder)|null
     */
    public function holder(): ?Model
    {
        return $this->resolve()[1];
    }

    /**
     * The name of the guard holder() resolved the holder from, or null when
     * no configured guard has an authenticated holder.
     */
    public function guard(): ?string
    {
        return $this->resolve()[0];
    }

    /**
     * Whether an authenticated holder was found on any configured guard.
     */
    public function check(): bool
    {
        return $this->holder() !== null;
    }

    /**
     * @return Model&WalletHolder
     *
     * @throws NoAuthenticatedHolder when no configured guard has an authenticated holder
     */
    public function holderOrFail(): Model
    {
        return $this->holder() ?? throw NoAuthenticatedHolder::acrossGuards($this->configuredGuards());
    }

    /**
     * The holder's named wallet (the configured default when no name is
     * given), created on first use, or null when there is no authenticated
     * holder.
     */
    public function wallet(?string $name = null): ?Wallet
    {
        return $this->holder()?->wallet($name);
    }

    /**
     * @throws NoAuthenticatedHolder when no configured guard has an authenticated holder
     */
    public function walletOrFail(?string $name = null): Wallet
    {
        return $this->holderOrFail()->wallet($name);
    }

    /**
     * The authenticated holder on one specific guard, regardless of
     * [wallet.guards]. Unlike holder(), $guard must be defined in
     * [auth.guards]: an undefined one is a caller mistake, not something to
     * skip silently.
     *
     * @return (Model&WalletHolder)|null
     *
     * @throws UnknownGuard when $guard is not defined in [auth.guards]
     */
    public function forGuard(string $guard): ?Model
    {
        if (! $this->guardDefined($guard)) {
            throw UnknownGuard::undefined($guard);
        }

        return $this->holderFromGuard($guard);
    }

    /**
     * @return array{0: ?string, 1: (Model&WalletHolder)|null}
     */
    private function resolve(): array
    {
        foreach ($this->configuredGuards() as $name) {
            if (! $this->guardDefined($name)) {
                continue;
            }

            $holder = $this->holderFromGuard($name);

            if ($holder !== null) {
                return [$name, $holder];
            }
        }

        return [null, null];
    }

    /**
     * The authenticated user on $guard, if it is both an Eloquent model and
     * one using HasWallet; null otherwise. $guard must already be known to
     * exist in [auth.guards].
     *
     * @return (Model&WalletHolder)|null
     */
    private function holderFromGuard(string $name): ?Model
    {
        $user = $this->auth->guard($name)->user();

        return $this->isHolder($user) ? $user : null;
    }

    /**
     * @phpstan-assert-if-true Model&WalletHolder $user
     */
    private function isHolder(mixed $user): bool
    {
        return $user instanceof Model && in_array(HasWallet::class, class_uses_recursive($user), true);
    }

    private function guardDefined(string $name): bool
    {
        return $this->config->get("auth.guards.{$name}") !== null;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfiguration when [wallet.guards] is not a list of non-empty strings
     */
    private function configuredGuards(): array
    {
        $guards = $this->config->get('wallet.guards', []);

        if (! is_array($guards) || ! array_is_list($guards)) {
            throw InvalidConfiguration::guardsNotListOfStrings($guards);
        }

        foreach ($guards as $guard) {
            if (! is_string($guard) || $guard === '') {
                throw InvalidConfiguration::guardsNotListOfStrings($guards);
            }
        }

        return $guards;
    }
}
