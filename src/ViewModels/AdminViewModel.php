<?php

declare(strict_types=1);

namespace App\ViewModels;

use App\Entities\User;

/**
 * View model for the admin user listing: the rows to render.
 *
 * Carries the app's {@see User} entities straight through — the template reads
 * only presentation-safe fields (username, role) off each. A typed VO the
 * template annotates once, like the other slices.
 *
 * The optional {@see $status} is a one-shot flash message read off the session
 * after a create/delete redirect (the post-redirect-get pattern), shown once at
 * the top of the listing. {@see $currentUserId} lets the template tell the admin
 * apart from the rows: their own row shows no edit/delete controls, mirroring the
 * ManageUser self-protection the server enforces anyway.
 */
final readonly class AdminViewModel
{
    /** @param list<User> $users */
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

    /** Whether the given row is the signed-in admin's own account. */
    public function isSelf(User $user): bool
    {
        return $user->id === $this->currentUserId;
    }
}
