<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/**
 * The sqlite mirror of database/migrations, for suites that swap the real
 * MariaDB connection for an in-memory database. One definition, so a column
 * added to a migration is added here once and every harness sees it.
 */
final class TestSchema
{
    /** An in-memory database with the whole schema already applied. */
    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::create($pdo);

        return $pdo;
    }

    public static function create(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                email_verified_at TEXT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                two_factor_secret TEXT NULL,
                two_factor_enabled_at TEXT NULL,
                two_factor_step INTEGER NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE user_recovery_codes (
                user_id INTEGER NOT NULL,
                hash TEXT NOT NULL,
                PRIMARY KEY (user_id, hash)
            )'
        );

        $pdo->exec(
            'CREATE TABLE api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NULL,
                last_used_at INTEGER NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE user_preferences (
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                value TEXT NOT NULL,
                PRIMARY KEY (user_id, name)
            )'
        );

        $pdo->exec(
            'CREATE TABLE activity (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                username TEXT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                query TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL,
                duration_ms INTEGER NOT NULL,
                ip TEXT NULL,
                user_agent TEXT NULL,
                referer TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                request_id TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                module TEXT NOT NULL,
                table_id TEXT NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                user_id INTEGER NULL,
                username TEXT NULL,
                message TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec(
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER NULL,
                reservation TEXT NULL,
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE scheduled_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task TEXT NOT NULL,
                outcome TEXT NOT NULL,
                items INTEGER NULL,
                held_minutes INTEGER NULL,
                error TEXT NULL,
                started_at INTEGER NOT NULL,
                duration_ms INTEGER NULL
            )'
        );
    }
}
