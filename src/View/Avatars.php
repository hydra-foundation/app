<?php

declare(strict_types=1);

namespace App\View;

use App\Admin\Avatar;
use App\Entities\User;
use Hydra\Admin\FileUrls;
use Hydra\Admin\ModuleRegistry;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Filesystem\Disks;

/**
 * Where a user's picture is fetched from. Shared with the layout rather than
 * answered once: the top bar asks on every page, and building the view must not
 * read the session, so the signed-in user is looked up when asked.
 */
final readonly class Avatars
{
    public function __construct(
        private GuardInterface $guard,
        private ModuleRegistry $registry,
        private Disks $disks,
    ) {}

    /** Who is signed in, when it is a user of this app. */
    public function user(): ?User
    {
        $user = $this->guard->user();

        return $user instanceof User ? $user : null;
    }

    /** The signed-in user's picture, or null for the icon. */
    public function current(): ?string
    {
        $user = $this->user();

        return $user === null ? null : $this->of($user);
    }

    public function icon(): string
    {
        return Avatar::ICON;
    }

    public function of(User $user): ?string
    {
        return $user->avatar === null ? null : $this->urls()->url($user->avatar);
    }

    private function urls(): FileUrls
    {
        return new FileUrls($this->registry->prefix(), $this->disks);
    }
}
