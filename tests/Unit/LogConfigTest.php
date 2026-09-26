<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\LogConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the log path key and its stderr fallback. A misread path sends the log
 * somewhere nobody is watching rather than failing, so the default matters as
 * much as the mapping.
 */
#[CoversClass(LogConfig::class)]
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
        self::scrub();

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private static function scrub(): void
    {
        foreach (['LOG_PATH', 'LOG_STDERR', 'LOG_READ_BYTES'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    private function fromEnv(string $contents): LogConfig
    {
        self::scrub();
        file_put_contents($this->dir . '/.env', $contents);
        return LogConfig::fromEnvironment(new Environment($this->dir), '/srv/app');
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

    public function test_defaults_to_a_file_under_the_app_as_well_as_stderr(): void
    {
        $config = $this->fromEnv("APP_NAME=x\n");

        $this->assertSame('/srv/app/storage/logs/hydra.log', $config->path);
        $this->assertTrue($config->stderr);
        $this->assertTrue($config->isFile());
        $this->assertSame(2_097_152, $config->readBytes);
    }

    public function test_a_relative_path_is_under_the_app_and_a_stream_is_left_alone(): void
    {
        $this->assertSame('/srv/app/var/app.log', $this->fromEnv("LOG_PATH=var/app.log\n")->path);
        $this->assertSame('/srv/app/storage/logs/hydra.log', $this->fromEnv("LOG_PATH=\n")->path);

        $stderr = $this->fromEnv("LOG_PATH=php://stderr\n");

        $this->assertSame('php://stderr', $stderr->path);
        $this->assertFalse($stderr->isFile());
    }

    public function test_stderr_can_be_turned_off_and_is_never_written_twice(): void
    {
        $this->assertFalse($this->fromEnv("LOG_STDERR=false\n")->alsoStderr());
        $this->assertTrue($this->fromEnv("LOG_STDERR=true\n")->alsoStderr());
        $this->assertFalse($this->fromEnv("LOG_PATH=php://stderr\nLOG_STDERR=true\n")->alsoStderr());
    }

    public function test_the_read_window_is_configurable_with_a_floor(): void
    {
        $this->assertSame(1_000_000, $this->fromEnv("LOG_READ_BYTES=1000000\n")->readBytes);
        $this->assertSame(65_536, $this->fromEnv("LOG_READ_BYTES=10\n")->readBytes);
    }
}
