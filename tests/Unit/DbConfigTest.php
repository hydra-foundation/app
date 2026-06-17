<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\DbConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

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

    public function testMapsEnvironmentKeys(): void
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

    public function testAppliesDefaultsWhenKeysAbsent(): void
    {
        $config = $this->fromEnv("DB_NAME=hydra\n");

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('utf8mb4', $config->charset);
    }

    public function testBuildsMysqlDsnForMariadb(): void
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

    public function testBuildsSqliteDsnFromDatabasePath(): void
    {
        $config = $this->fromEnv(
            "DB_DRIVER=sqlite\n" .
            "DB_NAME=:memory:\n"
        );

        $this->assertSame('sqlite::memory:', $config->dsn());
    }
}
