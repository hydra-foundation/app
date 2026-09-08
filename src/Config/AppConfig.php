<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Application's core settings
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
        public bool $trustForwardedProto,
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
            // Off by default because X-Forwarded-Proto is client-supplied:
            // only turn this on when a proxy we control (Traefik in the
            // dev/prod stacks) terminates TLS and sets the header — otherwise
            // any direct client could spoof "https" past the redirect.
            trustForwardedProto: $env->bool('TRUST_FORWARDED_PROTO', false),
        );
    }
}
