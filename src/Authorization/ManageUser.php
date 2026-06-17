<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * May the current admin manage THIS user — edit or delete them?
 *
 * The slice's subject-bound rule, and the reason it stays in the controller as
 * `gate->authorize(ManageUser::class, $target)` rather than becoming route
 * middleware: the verdict depends on *which* record is being acted on, which a
 * bare class-string middleware can't carry (see the roadmap's deferred
 * "authorize-as-middleware with params"). {@see AccessAdmin}, by contrast, is a
 * flat role check and so rides the route as RequireAdmin.
 *
 * The one rule here is self-protection: an admin may manage any account but
 * their own. It stops an admin deleting themselves out of the system or
 * demoting away their own admin role and getting locked out — the admin screen
 * is for managing OTHER users; your own account is changed elsewhere. The role
 * gate (AccessAdmin) has already run as middleware, so by the time this ability
 * is consulted the user is known to be an admin; the only question left is the
 * subject identity.
 */
final class ManageUser implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user instanceof User
            && $subject instanceof User
            && $user->id !== $subject->id;
    }
}
