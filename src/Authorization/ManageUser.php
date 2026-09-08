<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * Manage user ability
 *
 * May the current admin manage THIS user — edit or delete them?
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
