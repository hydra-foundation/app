<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\LogConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

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

    public function testExposesReadonlyPathFromConstructor(): void
    {
        $config = new LogConfig(path: '/var/log/app.log');

        $this->assertSame('/var/log/app.log', $config->path);
    }

    public function testMapsLogPath(): void
    {
        $this->assertSame('/tmp/app.log', $this->fromEnv("LOG_PATH=/tmp/app.log\n")->path);
    }

    public function testDefaultsToStderr(): void
    {
        $this->assertSame('php://stderr', $this->fromEnv("APP_NAME=x\n")->path);
    }
}
