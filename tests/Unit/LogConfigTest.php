<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\LogConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Pins the log path key and its stderr fallback. A misread path sends the log
 * somewhere nobody is watching rather than failing, so the default matters as
 * much as the mapping.
 */
final class LogConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-logconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV; scrub so values don't leak.
        putenv('LOG_PATH');
        unset($_ENV['LOG_PATH']);

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): LogConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return LogConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_exposes_readonly_path_from_constructor(): void
    {
        $config = new LogConfig(path: '/var/log/app.log');

        $this->assertSame('/var/log/app.log', $config->path);
    }

    public function test_maps_log_path(): void
    {
        $this->assertSame('/tmp/app.log', $this->fromEnv("LOG_PATH=/tmp/app.log\n")->path);
    }

    public function test_defaults_to_stderr(): void
    {
        $this->assertSame('php://stderr', $this->fromEnv("APP_NAME=x\n")->path);
    }
}
