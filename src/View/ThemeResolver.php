<?php

declare(strict_types=1);

namespace App\View;

use App\Repositories\PreferenceRepository;
use Hydra\Auth\Contracts\GuardInterface;

/**
 * Which palette this visitor gets. A preference belongs to a person, so a
 * signed-out page (the login screen, the public home page) has nobody to ask
 * and takes the fallback.
 */
final class ThemeResolver
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
        private readonly Themes $themes,
    ) {}

    public function current(): string
    {
        $user = $this->guard->user();

        if ($user === null) {
            return Themes::FALLBACK;
        }

        // Resolved rather than trusted: a theme can be deleted from disk while
        // somebody still has it saved, and a missing palette renders unstyled.
        return $this->themes->resolve(
            $this->preferences->get($user->getAuthIdentifier(), Themes::PREFERENCE),
        );
    }
}
