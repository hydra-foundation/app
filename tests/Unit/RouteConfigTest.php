<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\RouteConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Pins ROUTE_CACHE to off unless it is explicitly truthy. Defaulting the other
 * way would serve a stale route table on any machine that never built one.
 */
final class RouteConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-routeconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV; scrub so values don't leak.
        putenv('ROUTE_CACHE');
        unset($_ENV['ROUTE_CACHE']);

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): RouteConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return RouteConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_exposes_readonly_cache_flag_from_constructor(): void
    {
        $this->assertTrue((new RouteConfig(cache: true))->cache);
    }

    public function test_defaults_to_disabled_when_unset(): void
    {
        // Off by default: local dev wants #[Route] edits to take effect at once.
        $this->assertFalse($this->fromEnv("APP_NAME=x\n")->cache);
    }

    public function test_enables_when_route_cache_is_truthy(): void
    {
        $this->assertTrue($this->fromEnv("ROUTE_CACHE=true\n")->cache);
    }

    public function test_stays_disabled_when_route_cache_is_falsey(): void
    {
        $this->assertFalse($this->fromEnv("ROUTE_CACHE=false\n")->cache);
    }
}
