<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Logging settings
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
