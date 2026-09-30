<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Widgets\MigrationsWidget;
use Hydra\Admin\Widgets\Status;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What you check after a deploy: whether the schema caught up with the code.
 * Like every health card, a probe that fails is a card, never an exception.
 */
#[CoversClass(MigrationsWidget::class)]
final class MigrationsWidgetTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->dir = sys_get_temp_dir() . '/hydra-migrations-widget-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_an_up_to_date_schema_is_ok_and_says_when_it_last_moved(): void
    {
        $this->file('20260101_000000_create_a.sql');
        $this->file('20260102_000000_create_b.sql');
        $this->runner()->run();
        $this->pdo->exec("UPDATE migrations SET applied_at = '2026-09-30 09:00:00'");

        $card = $this->widget('2026-09-30 12:30:00')->present();

        $this->assertSame(Status::Ok, $card['status']);
        $this->assertSame('Up to date', $card['headline']);
        $this->assertSame('last run 3h 30m ago', $card['caption']);
        $this->assertSame(
            [['label' => 'Applied', 'value' => '2'], ['label' => 'Last', 'value' => '20260102_000000_create_b.sql']],
            $card['rows'],
        );
        $this->assertNull($card['note']);
    }

    public function test_one_pending_migration_is_a_warning_that_names_it(): void
    {
        $this->file('20260101_000000_create_a.sql');
        $this->runner()->run();
        $this->file('20261001_000000_add_x.sql');

        $card = $this->widget()->present();

        $this->assertSame(Status::Warning, $card['status']);
        $this->assertSame('1 pending', $card['headline']);
        $this->assertSame('run ./hydra migrate:run', $card['caption']);
        $this->assertSame('20261001_000000_add_x.sql', $card['note']);
    }

    public function test_a_long_pending_list_names_five_and_counts_the_rest(): void
    {
        foreach (range(1, 6) as $n) {
            $this->file(sprintf('2026100%d_000000_m%d.sql', $n, $n));
        }

        $card = $this->widget()->present();

        $this->assertSame('6 pending', $card['headline']);
        $this->assertSame(
            '20261001_000000_m1.sql, 20261002_000000_m2.sql, 20261003_000000_m3.sql, 20261004_000000_m4.sql, 20261005_000000_m5.sql and 1 more',
            $card['note'],
        );
        $this->assertSame([['label' => 'Applied', 'value' => '0']], $card['rows'], 'nothing ran yet: no Last row');
    }

    public function test_an_app_with_no_migrations_is_up_to_date(): void
    {
        $card = $this->widget()->present();

        $this->assertSame(Status::Ok, $card['status']);
        $this->assertSame('Up to date', $card['headline']);
        $this->assertSame('no migrations', $card['caption']);
    }

    public function test_a_runner_that_fails_is_a_card_and_not_an_exception(): void
    {
        // The sqlite connection cannot answer the mysql catalogue query.
        $card = (new MigrationsWidget(new MigrationRunner($this->pdo, $this->dir, 'mysql'), new FrozenClock('2026-09-30 12:00:00')))->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('No answer', $card['headline']);
        $this->assertIsString($card['note']);
        $this->assertStringContainsString('information_schema', $card['note']);
    }

    private function widget(string $now = '2026-09-30 12:00:00'): MigrationsWidget
    {
        return new MigrationsWidget($this->runner(), new FrozenClock($now . ' UTC'));
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->dir, 'sqlite');
    }

    private function file(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, 'SELECT 1');
    }
}
