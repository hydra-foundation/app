<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Typed, immutable view of the logging settings.
 *
 * The default sink is php://stderr, which surfaces records via `docker logs`.
 * An unwritable path is the binding's concern (it falls back to stderr), not
 * this object's — config carries the intent, the wiring handles failure.
 */
final readonly class LogConfig
{
    public function __construct(
        public string $path,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            path: $env->string('LOG_PATH', 'php://stderr'),
        );
    }
}
