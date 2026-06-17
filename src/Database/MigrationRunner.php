<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Applies raw .sql migration files and records which have run.
 *
 * Hydra's migrations are plain .sql — the data-layer equivalent of its
 * hand-written repositories: no ORM, no fluent builder, just SQL. Each file in
 * the migrations directory is a forward-only change; there are no down
 * migrations by design (rollback in production is mostly fiction, and in dev you
 * re-run from scratch with {@see fresh()}).
 *
 * Files are applied in lexical order, so the timestamp prefix the
 * make:migration command writes ({Ymd_His}_name.sql) doubles as the ordering
 * key. A `migrations` table records each applied filename; a file already in
 * that table is skipped on the next run.
 *
 * This class takes the raw PDO rather than {@see ConnectionInterface} on
 * purpose: DDL is multi-statement and unparameterised, so it runs through
 * PDO::exec — outside the prepared-statement-only seam the repositories use.
 *
 * Caveat worth knowing: MariaDB has no transactional DDL (DDL implicitly
 * commits), so a migration that fails halfway leaves partial state that cannot
 * be rolled back. Keep each migration to one logical change.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        private readonly string $driver,
    ) {}

    /**
     * Apply every pending migration in order and return the filenames applied
     * during this call (empty when already up to date).
     *
     * @return list<string>
     */
    public function run(): array
    {
        $this->ensureTable();

        $applied = [];
        foreach ($this->pending() as $filename) {
            $sql = file_get_contents($this->migrationsPath . '/' . $filename);
            $this->pdo->exec($sql);
            $this->record($filename);
            $applied[] = $filename;
        }

        return $applied;
    }

    /**
     * Drop every table, then re-apply all migrations from scratch. Destructive —
     * the calling command guards it. Returns the filenames applied.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $this->dropAllTables();

        return $this->run();
    }

    /**
     * Every migration on disk paired with whether it has been applied, in
     * order — the data behind migrate:status.
     *
     * @return list<array{filename: string, applied: bool}>
     */
    public function status(): array
    {
        $this->ensureTable();

        $applied = $this->appliedFilenames();

        return array_map(
            static fn (string $filename): array => [
                'filename' => $filename,
                'applied' => in_array($filename, $applied, true),
            ],
            $this->migrationFiles(),
        );
    }

    /** Migration files on disk that have not yet been recorded as applied.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $this->ensureTable();

        $applied = $this->appliedFilenames();

        return array_values(array_filter(
            $this->migrationFiles(),
            static fn (string $filename): bool => !in_array($filename, $applied, true),
        ));
    }

    /** Create the tracking table if it does not exist. Portable across the
     *  mysql and sqlite drivers Hydra targets. */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . ' filename VARCHAR(255) NOT NULL,'
            . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (filename))',
        );
    }

    /** @return list<string> filenames recorded as applied, in order */
    private function appliedFilenames(): array
    {
        $statement = $this->pdo->query('SELECT filename FROM migrations ORDER BY filename');

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> *.sql filenames in the migrations directory, sorted */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = array_map('basename', glob($this->migrationsPath . '/*.sql') ?: []);
        sort($files);

        return $files;
    }

    private function record(string $filename): void
    {
        $statement = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $statement->execute([$filename]);
    }

    /**
     * Drop every table in the current database. Driver-aware: MariaDB enumerates
     * via information_schema and toggles FK checks; sqlite uses sqlite_master and
     * a PRAGMA. Migrations target MariaDB, but the sqlite branch keeps fresh()
     * exercisable by the test suite.
     */
    private function dropAllTables(): void
    {
        if ($this->driver === 'sqlite') {
            $tables = $this->pdo
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS "' . $table . '"');
            }
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            return;
        }

        $tables = $this->pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
