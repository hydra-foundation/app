<?php

declare(strict_types=1);

namespace App\View;

use App\Repositories\PreferenceRepository;
use DateTimeZone;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Auth\Contracts\GuardInterface;

/**
 * Which clock this visitor reads by. A zone belongs to a person the way a
 * palette does, so a signed-out page has nobody to ask and takes the
 * application's own, which is APP_TIMEZONE and is UTC unless it is set.
 *
 * Only ever display: the process, the database and everything stored stay in
 * UTC, and this shifts instants at the last moment before they are read.
 */
final class TimezoneResolver implements TimezoneInterface
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
        private readonly Timezones $timezones,
    ) {}

    public function current(): string
    {
        $user = $this->guard->user();

        if ($user === null) {
            return $this->timezones->fallback();
        }

        // Resolved rather than trusted, as the theme is: a zone can be retired
        // from the database while somebody still has it saved, and an
        // identifier PHP no longer knows throws rather than rendering.
        return $this->timezones->resolve(
            $this->preferences->get($user->getAuthIdentifier(), Timezones::PREFERENCE),
        );
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->current());
    }
}
