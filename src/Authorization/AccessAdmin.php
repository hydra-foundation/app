<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * May the current user reach the admin area at all? Unscoped, unlike
 * {@see ManageUser}, because the answer is the same on every screen inside it.
 */
final class AccessAdmin implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user instanceof User && $user->isAdmin();
    }
}
