<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\AppConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Pins the environment keys the app reads and the defaults it falls back to,
 * because a renamed key fails silently: the config still builds, just with the
 * default, and the wrong setting only surfaces in production.
 */
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
        $this->scrubProcessEnv();

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    /**
     * Environment exports .env values to putenv()/$_ENV, and the real process
     * environment beats the file, so scrub the keys this suite touches before
     * every Environment construction (and in tearDown), or one fromEnv() call's
     * exports would override the next one's .env.
     */
    private function scrubProcessEnv(): void
    {
        foreach (['APP_NAME', 'APP_URL', 'APP_DEBUG', 'APP_TIMEZONE', 'APP_KEY', 'FORCE_HTTPS', 'TRUST_FORWARDED_PROTO', 'TRUSTED_PROXIES'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function fromEnv(string $contents): AppConfig
    {
        $this->scrubProcessEnv();
        file_put_contents($this->dir . '/.env', $contents);
        return AppConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_exposes_readonly_fields_from_constructor(): void
    {
        $config = new AppConfig(
            name: 'Hydra',
            url: 'http://hydra.localhost',
            debug: true,
            timezone: 'UTC',
            key: 'secret',
            forceHttps: true,
            trustForwardedProto: true,
            trustedProxies: ['10.0.0.0/8'],
        );

        $this->assertSame('Hydra', $config->name);
        $this->assertSame('http://hydra.localhost', $config->url);
        $this->assertTrue($config->debug);
        $this->assertSame('UTC', $config->timezone);
        $this->assertSame('secret', $config->key);
        $this->assertTrue($config->forceHttps);
        $this->assertTrue($config->trustForwardedProto);
        $this->assertSame(['10.0.0.0/8'], $config->trustedProxies);
    }

    public function test_maps_environment_keys(): void
    {
        $config = $this->fromEnv(
            "APP_NAME=MyApp\n" .
            "APP_URL=https://example.test\n" .
            "APP_DEBUG=false\n" .
            "APP_TIMEZONE=America/Toronto\n" .
            "APP_KEY=deadbeef\n" .
            "FORCE_HTTPS=true\n" .
            "TRUST_FORWARDED_PROTO=true\n" .
            "TRUSTED_PROXIES=10.0.0.0/8, 172.18.0.0/16\n"
        );

        $this->assertSame('MyApp', $config->name);
        $this->assertSame('https://example.test', $config->url);
        $this->assertFalse($config->debug);
        $this->assertSame('America/Toronto', $config->timezone);
        $this->assertSame('deadbeef', $config->key);
        $this->assertTrue($config->forceHttps);
        $this->assertTrue($config->trustForwardedProto);
        $this->assertSame(['10.0.0.0/8', '172.18.0.0/16'], $config->trustedProxies);
    }

    public function test_applies_defaults_when_keys_absent(): void
    {
        $config = $this->fromEnv("APP_URL=http://localhost\n");

        $this->assertSame('Hydra', $config->name);
        $this->assertSame('UTC', $config->timezone);
        $this->assertFalse($config->debug, 'debug defaults to false (production-safe)');
        $this->assertSame('', $config->key);
        $this->assertFalse($config->forceHttps, 'forceHttps defaults to false (local http dev)');
        $this->assertFalse($config->trustForwardedProto, 'header trust must be an explicit opt-in');
        $this->assertSame([], $config->trustedProxies, 'no proxy is trusted until one is named');
    }

    public function test_parses_debug_as_boolean(): void
    {
        $this->assertTrue($this->fromEnv("APP_DEBUG=true\n")->debug);
        $this->assertTrue($this->fromEnv("APP_DEBUG=1\n")->debug);
        $this->assertFalse($this->fromEnv("APP_DEBUG=off\n")->debug);
    }
}
