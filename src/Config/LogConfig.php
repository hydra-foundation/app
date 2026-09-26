<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Logging settings
 */
final readonly class LogConfig
{
    public const DEFAULT_PATH = 'storage/logs/hydra.log';
    public const DEFAULT_READ_BYTES = 2_097_152;
    public const MIN_READ_BYTES = 65_536;

    /**
     * @param bool $stderr write to stderr as well, for `docker logs`
     * @param int $readBytes how far back from the end the admin reads the file
     */
    public function __construct(
        public string $path,
        public bool $stderr = true,
        public int $readBytes = self::DEFAULT_READ_BYTES,
    ) {}

    /** @param string|null $root what a relative LOG_PATH is under; the app directory by default */
    public static function fromEnvironment(Environment $env, ?string $root = null): self
    {
        $path = $env->string('LOG_PATH') ?: self::DEFAULT_PATH;

        if (!str_contains($path, '://') && !str_starts_with($path, '/')) {
            $path = ($root ?? dirname(__DIR__, 2)) . '/' . $path;
        }

        return new self(
            path: $path,
            stderr: $env->bool('LOG_STDERR', true),
            readBytes: max(self::MIN_READ_BYTES, $env->int('LOG_READ_BYTES', self::DEFAULT_READ_BYTES)),
        );
    }

    /** A file the admin can read back, rather than a stream like php://stderr. */
    public function isFile(): bool
    {
        return !str_contains($this->path, '://');
    }

    public function alsoStderr(): bool
    {
        return $this->stderr && $this->path !== 'php://stderr';
    }
}
