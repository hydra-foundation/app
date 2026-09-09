<?php

declare(strict_types=1);

namespace App\ViewModels;

use App\Entities\User;

/**
 * Admin view model
 *
 * View model for the admin user
 */
final readonly class AdminViewModel
{
    public function __construct(
        public User $currentUser
    ) {}

    public function isSelf(User $user): bool
    {
        return $user->id === $this->currentUser->id;
    }
}
