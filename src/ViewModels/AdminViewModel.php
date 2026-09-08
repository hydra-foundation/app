<?php

declare(strict_types=1);

namespace App\ViewModels;

use App\Entities\User;

/**
 * Admin view model
 *
 * View model for the admin user listing: the rows to render
 */
final readonly class AdminViewModel
{
    public function __construct(
        public array $users,
        public ?string $status = null,
        public ?int $currentUserId = null,
    ) {}

    public function count(): int
    {
        return count($this->users);
    }

    public function hasStatus(): bool
    {
        return $this->status !== null && $this->status !== '';
    }

    public function isSelf(User $user): bool
    {
        return $user->id === $this->currentUserId;
    }
}
