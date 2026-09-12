<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * May the current admin edit or delete THIS user? Scoped to a subject, unlike
 * {@see AccessAdmin}, because the answer differs per row the admin is looking at.
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
