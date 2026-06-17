<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Typed, immutable view of the application's core settings.
 *
 * Built once from {@see Environment} at boot via {@see fromEnvironment()} and
 * injected where needed, so the magic strings ("APP_DEBUG", ...) live in one
 * place and call sites read a typed field instead of a string bag.
 */
final readonly class AppConfig
{
    public function __construct(
        public string $name,
        public string $url,
        public bool $debug,
        public string $timezone,
        public string $key,
        public bool $forceHttps,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            name: $env->string('APP_NAME', 'Hydra'),
            url: $env->string('APP_URL'),
            debug: $env->bool('APP_DEBUG', false),
            timezone: $env->string('APP_TIMEZONE', 'UTC'),
            key: $env->string('APP_KEY'),
            // Off by default so local http dev is never redirected; turn on in
            // production (behind TLS or a TLS-terminating proxy).
            forceHttps: $env->bool('FORCE_HTTPS', false),
        );
    }
}
