<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\RouteConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

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

    public function testExposesReadonlyCacheFlagFromConstructor(): void
    {
        $this->assertTrue((new RouteConfig(cache: true))->cache);
    }

    public function testDefaultsToDisabledWhenUnset(): void
    {
        // Off by default: local dev wants #[Route] edits to take effect at once.
        $this->assertFalse($this->fromEnv("APP_NAME=x\n")->cache);
    }

    public function testEnablesWhenRouteCacheIsTruthy(): void
    {
        $this->assertTrue($this->fromEnv("ROUTE_CACHE=true\n")->cache);
    }

    public function testStaysDisabledWhenRouteCacheIsFalsey(): void
    {
        $this->assertFalse($this->fromEnv("ROUTE_CACHE=false\n")->cache);
    }
}
