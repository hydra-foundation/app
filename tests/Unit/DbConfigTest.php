<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\DbConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Pins the environment keys, the defaults, and the DSN each driver is handed,
 * the DSN especially: it is assembled from several settings, and a wrong one
 * fails as a connection error that says nothing about which setting was wrong.
 */
final class DbConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-dbconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): DbConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return DbConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_maps_environment_keys(): void
    {
        $config = $this->fromEnv(
            "DB_DRIVER=mariadb\n" .
            "DB_HOST=db\n" .
            "DB_PORT=3306\n" .
            "DB_NAME=hydra\n" .
            "DB_USERNAME=app\n" .
            "DB_PASSWORD=secret\n" .
            "DB_CHARSET=utf8mb4\n"
        );

        $this->assertSame('mariadb', $config->driver);
        $this->assertSame('db', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('hydra', $config->database);
        $this->assertSame('app', $config->username);
        $this->assertSame('secret', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
    }

    public function test_applies_defaults_when_keys_absent(): void
    {
        $config = $this->fromEnv("DB_NAME=hydra\n");

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('utf8mb4', $config->charset);
    }

    public function test_builds_mysql_dsn_for_mariadb(): void
    {
        // mariadb maps onto the mysql PDO driver.
        $config = $this->fromEnv(
            "DB_DRIVER=mariadb\n" .
            "DB_HOST=db\n" .
            "DB_PORT=3307\n" .
            "DB_NAME=hydra\n" .
            "DB_CHARSET=utf8mb4\n"
        );

        $this->assertSame('mysql:host=db;port=3307;dbname=hydra;charset=utf8mb4', $config->dsn());
    }

    public function test_builds_sqlite_dsn_from_database_path(): void
    {
        $config = $this->fromEnv(
            "DB_DRIVER=sqlite\n" .
            "DB_NAME=:memory:\n"
        );

        $this->assertSame('sqlite::memory:', $config->dsn());
    }
}
