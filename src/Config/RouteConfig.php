<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Routing settings
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
