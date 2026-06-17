<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * May the current user reach the admin area?
 *
 * The app's own authorization rule — the "noun" the hydra/authorization package
 * deliberately does not ship, the exact counterpart to how the app (not the auth
 * package) supplies the UserProviderInterface. The gate ships the mechanism;
 * what "admin" means is app policy, decided here.
 *
 * It reads the role straight off the app's {@see User} entity, so the permission
 * data lives in the app's own users table, never in the package. The user is
 * nullable because abilities run for anonymous visitors too; here that is simply
 * a deny. This ability takes no subject — it is a flat role check, not an
 * ownership check over a particular record.
 */
final class AccessAdmin implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user instanceof User && $user->isAdmin();
    }
}
