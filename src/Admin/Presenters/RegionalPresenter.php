<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\View\TimezoneResolver;
use DateTimeZone;
use App\View\Timezones;
use Hydra\Admin\Contracts\PresenterInterface;
use Psr\Clock\ClockInterface;

/**
 * The zones on offer, the one in force, and what the clock reads in it. The
 * sample is the point of the screen: a list of place names says nothing about
 * whether the setting is the right one, and a time that matches the reader's
 * own watch says it immediately.
 */
final class RegionalPresenter implements PresenterInterface
{
    public function __construct(
        private readonly Timezones $timezones,
        private readonly TimezoneResolver $resolver,
        private readonly ClockInterface $clock,
    ) {}

    public function present(): array
    {
        $selected = $this->resolver->current();

        return [
            'options' => $this->timezones->options(),
            'selected' => $selected,
            'sample' => $this->clock->now()->setTimezone($this->resolver->zone())->format('D j M Y, H:i'),
            // Converted rather than assumed: the process runs in UTC, but a
            // line labelled UTC has to be UTC whatever the process is set to.
            'stored' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
            'fallback' => $this->timezones->fallback(),
        ];
    }
}
