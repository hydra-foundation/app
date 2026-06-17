<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Typed, immutable view of the database connection settings.
 *
 * Built once from {@see Environment} at boot, same pattern as {@see AppConfig}.
 * The one piece of logic it carries is {@see dsn()}: turning the DB_* fields
 * into a PDO DSN string, including the mariadb->mysql driver mapping (MariaDB
 * speaks the mysql PDO driver) and the sqlite shape used by the test suite.
 */
final readonly class DbConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $charset,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            driver: $env->string('DB_DRIVER', 'mysql'),
            host: $env->string('DB_HOST', 'localhost'),
            port: $env->int('DB_PORT', 3306),
            database: $env->string('DB_NAME'),
            username: $env->string('DB_USERNAME'),
            password: $env->string('DB_PASSWORD'),
            charset: $env->string('DB_CHARSET', 'utf8mb4'),
        );
    }

    /**
     * Build the PDO DSN for this connection. sqlite (used by tests) takes just
     * a path; everything else assembles a mysql DSN — mariadb included, since
     * it uses the mysql PDO driver.
     */
    public function dsn(): string
    {
        if ($this->driver === 'sqlite') {
            return 'sqlite:' . $this->database;
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset,
        );
    }
}
