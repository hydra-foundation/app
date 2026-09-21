<?php

declare(strict_types=1);

namespace App\View;

use App\Repositories\PreferenceRepository;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Psr\Clock\ClockInterface;

/**
 * Which palette this visitor gets. A preference belongs to a person, so a
 * signed-out page (the login screen, the public home page) has nobody to ask
 * and takes the fallback.
 */
final class ThemeResolver
{
    /** The working day auto keeps paper for, in hours of the reader's clock. */
    public const DAY_STARTS = 8;

    public const DAY_ENDS = 18;

    public function __construct(
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
        private readonly Themes $themes,
        private readonly ClockInterface $clock,
        private readonly TimezoneInterface $timezone,
    ) {}

    /** What this visitor chose, which the picker shows: a palette, or AUTO. */
    public function choice(): string
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

    /** The palette the page is painted in. Never AUTO. */
    public function current(): string
    {
        $choice = $this->choice();

        if ($choice !== Themes::AUTO) {
            return $choice;
        }

        $hour = (int) $this->clock->now()->setTimezone($this->timezone->zone())->format('G');
        $day = $hour >= self::DAY_STARTS && $hour < self::DAY_ENDS;

        return $this->themes->resolve($day ? Themes::DAY : Themes::NIGHT);
    }
}
