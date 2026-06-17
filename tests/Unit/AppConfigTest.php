<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\AppConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-appconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV, so values leak across tests via
        // getenv() unless we scrub the keys this suite touches.
        foreach (['APP_NAME', 'APP_URL', 'APP_DEBUG', 'APP_TIMEZONE', 'APP_KEY', 'FORCE_HTTPS'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): AppConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return AppConfig::fromEnvironment(new Environment($this->dir));
    }

    public function testExposesReadonlyFieldsFromConstructor(): void
    {
        $config = new AppConfig(
            name: 'Hydra',
            url: 'http://hydra.localhost',
            debug: true,
            timezone: 'UTC',
            key: 'secret',
            forceHttps: true,
        );

        $this->assertSame('Hydra', $config->name);
        $this->assertSame('http://hydra.localhost', $config->url);
        $this->assertTrue($config->debug);
        $this->assertSame('UTC', $config->timezone);
        $this->assertSame('secret', $config->key);
        $this->assertTrue($config->forceHttps);
    }

    public function testMapsEnvironmentKeys(): void
    {
        $config = $this->fromEnv(
            "APP_NAME=MyApp\n" .
            "APP_URL=https://example.test\n" .
            "APP_DEBUG=false\n" .
            "APP_TIMEZONE=America/Toronto\n" .
            "APP_KEY=deadbeef\n" .
            "FORCE_HTTPS=true\n"
        );

        $this->assertSame('MyApp', $config->name);
        $this->assertSame('https://example.test', $config->url);
        $this->assertFalse($config->debug);
        $this->assertSame('America/Toronto', $config->timezone);
        $this->assertSame('deadbeef', $config->key);
        $this->assertTrue($config->forceHttps);
    }

    public function testAppliesDefaultsWhenKeysAbsent(): void
    {
        $config = $this->fromEnv("APP_URL=http://localhost\n");

        $this->assertSame('Hydra', $config->name);
        $this->assertSame('UTC', $config->timezone);
        $this->assertFalse($config->debug, 'debug defaults to false (production-safe)');
        $this->assertSame('', $config->key);
        $this->assertFalse($config->forceHttps, 'forceHttps defaults to false (local http dev)');
    }

    public function testParsesDebugAsBoolean(): void
    {
        $this->assertTrue($this->fromEnv("APP_DEBUG=true\n")->debug);
        $this->assertTrue($this->fromEnv("APP_DEBUG=1\n")->debug);
        $this->assertFalse($this->fromEnv("APP_DEBUG=off\n")->debug);
    }
}
