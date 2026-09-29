<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entities\User;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SessionGuard;

/**
 * Every way into one account, ended at once: each browser signed in and each
 * API token. For offboarding, and for "someone else is in my account". On the
 * account doing it, the sign-in making the request is kept, as a password
 * change keeps it: an admin locking their own account down is not thrown out
 * mid-click.
 */
final class SignOutEverywhere
{
    public function __construct(
        private readonly SignInStoreInterface $signIns,
        private readonly ApiTokenStoreInterface $tokens,
        private readonly SessionGuard $guard,
    ) {}

    /** @return string what was revoked, said for the admin */
    public function __invoke(AuthenticatableInterface $user): string
    {
        $yours = (string) $this->guard->id() === (string) $user->getAuthIdentifier();
        $signIns = $this->signIns->revokeAll($user, except: $yours ? $this->guard->signIn()?->id : null);
        $tokens = $this->tokens->revokeAll($user);

        if ($yours) {
            return $signIns === 0 && $tokens === 0
                ? 'You had no other sign-ins or API tokens to revoke.'
                : sprintf('You are signed out everywhere else: %s and %s revoked.', self::count($signIns, 'other sign-in'), self::count($tokens, 'API token'));
        }

        $name = $user instanceof User ? $user->username : (string) $user->getAuthIdentifier();

        return $signIns === 0 && $tokens === 0
            ? "{$name} had no sign-ins or API tokens to revoke."
            : sprintf('%s is signed out everywhere: %s and %s revoked.', $name, self::count($signIns, 'sign-in'), self::count($tokens, 'API token'));
    }

    private static function count(int $n, string $thing): string
    {
        return $n === 1 ? "1 {$thing}" : "{$n} {$thing}s";
    }
}
