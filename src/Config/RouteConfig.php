<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Typed, immutable view of the routing settings.
 *
 * Just the cache toggle for now. It defaults OFF because the route cache is a
 * production optimisation: with it on, controller #[Route] edits don't take
 * effect until the cache is rebuilt (`php bin/console route:cache`), which is
 * exactly the wrong behaviour for local development. The cache file location is
 * a wiring detail of the Router binding, not a knob — so it isn't here.
 */
final readonly class RouteConfig
{
    public function __construct(
        public bool $cache,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            cache: $env->bool('ROUTE_CACHE', false),
        );
    }
}
