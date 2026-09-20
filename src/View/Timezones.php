<?php

declare(strict_types=1);

namespace App\View;

use DateTimeZone;

/**
 * What zones this installation offers, taken from PHP's own database rather
 * than a list here: the list changes when the world changes, and a hand-kept
 * copy would be wrong the first time a country moved its clocks.
 *
 * Grouped by region because four hundred names in one select is not a choice
 * anybody can make, and the region is the first thing a person knows about
 * where they are.
 */
final class Timezones
{
    /**
     * The name the choice is stored under, shared by the screen that writes it
     * and the resolver that reads it back.
     */
    public const PREFERENCE = 'timezone';

    /** @var array<string, array<string, string>>|null */
    private ?array $options = null;

    /** The zone a person gets before they have chosen one. */
    public function __construct(private readonly string $fallback = 'UTC') {}

    public function fallback(): string
    {
        return $this->fallback;
    }

    public function has(string $name): bool
    {
        // in_array over the identifier list, not a DateTimeZone constructor:
        // "+05:00" and "EST" both build happily and neither is a place, so
        // neither belongs in a picker that says where somebody is.
        return in_array($name, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * region => [identifier => label], in listing order. UTC is offered on its
     * own, since it is the fallback and is not in any region.
     *
     * @return array<string, array<string, string>>
     */
    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = ['UTC' => ['UTC' => 'UTC']];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $parts = explode('/', $identifier, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $options[$parts[0]][$identifier] = str_replace('_', ' ', $parts[1]);
        }

        return $this->options = $options;
    }

    /** The zone to use, given what someone asked for. */
    public function resolve(?string $name): string
    {
        return $name !== null && $this->has($name) ? $name : $this->fallback;
    }
}
