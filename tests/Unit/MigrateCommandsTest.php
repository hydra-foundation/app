<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\MakeMigrationCommand;
use App\Console\Commands\MigrateFreshCommand;
use App\Console\Commands\MigrateRunCommand;
use App\Console\Commands\MigrateStatusCommand;
use App\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The migrate:* and make:migration commands wired to a real MigrationRunner over
 * an in-memory sqlite PDO and a temp directory — covering the dev-mode guard on
 * migrate:fresh and the timestamped scaffolding of make:migration.
 */
final class MigrateCommandsTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->dir = sys_get_temp_dir() . '/hydra-migrate-cmd-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->dir, 'sqlite');
    }

    private function write(string $filename, string $sql): void
    {
        file_put_contents($this->dir . '/' . $filename, $sql);
    }

    public function testMigrateRunAppliesAndIsIdempotent(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $tester = new CommandTester(new MigrateRunCommand($this->runner()));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Applied 1 migration', $tester->getDisplay());

        $tester = new CommandTester(new MigrateRunCommand($this->runner()));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testMigrateStatusShowsAppliedAndPending(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $this->runner()->run();
        $this->write('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $tester = new CommandTester(new MigrateStatusCommand($this->runner()));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('applied', $display);
        $this->assertStringContainsString('pending', $display);
    }

    public function testMigrateFreshRefusesOutsideDebugWithoutForce(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $tester = new CommandTester(new MigrateFreshCommand($this->runner(), debug: false));
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('APP_DEBUG is off', $tester->getDisplay());
    }

    public function testMigrateFreshRunsOutsideDebugWithForce(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $tester = new CommandTester(new MigrateFreshCommand($this->runner(), debug: false));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        $this->assertStringContainsString('Database reset', $tester->getDisplay());
    }

    public function testMigrateFreshConfirmsInDebugMode(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $tester = new CommandTester(new MigrateFreshCommand($this->runner(), debug: true));
        $tester->setInputs(['yes']);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Database reset', $tester->getDisplay());
    }

    public function testMigrateFreshAbortsWhenDeclined(): void
    {
        $tester = new CommandTester(new MigrateFreshCommand($this->runner(), debug: true));
        $tester->setInputs(['no']);
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function testMakeMigrationCreatesTimestampedFile(): void
    {
        $tester = new CommandTester(new MakeMigrationCommand($this->dir));
        $this->assertSame(Command::SUCCESS, $tester->execute(['name' => 'Create Posts Table']));

        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{8}_\d{6}_create_posts_table\.sql$/', $files[0]);

        $body = file_get_contents($this->dir . '/' . $files[0]);
        $this->assertStringContainsString('-- Migration: Create Posts Table', $body);
        $this->assertStringContainsString('Forward-only', $body);
    }

    public function testMakeMigrationRejectsAnEmptySlug(): void
    {
        $tester = new CommandTester(new MakeMigrationCommand($this->dir));
        $this->assertSame(Command::FAILURE, $tester->execute(['name' => '!!!']));
        $this->assertSame([], glob($this->dir . '/*.sql') ?: []);
    }
}
