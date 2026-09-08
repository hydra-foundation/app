<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;

/**
 * Access admin ability
 *
 * May the current user reach the admin area?
 */
final class AccessAdmin implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user instanceof User && $user->isAdmin();
    }
}
