<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Database\MigrationRunner;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * System Health's cards through the real routes: the page draws them, and
 * each fills itself from its own URL.
 */
#[CoversNothing]
final class SystemHealthFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;
    private string $dir;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->http = $this->app->http();

        // Migrations of its own, over the suite's database, so the card's
        // answer is this test's to decide.
        $this->dir = sys_get_temp_dir() . '/hydra-health-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app->container()->instance(MigrationRunner::class, new MigrationRunner($this->app->pdo(), $this->dir, 'sqlite'));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_the_page_draws_a_migrations_card_after_updates(): void
    {
        $this->login('boss');

        $this->assertMatchesRegularExpression(
            '~id="admin-widget-updates".*?id="admin-widget-migrations"~s',
            $this->http->get('/admin/system-health')->assertOk()->body(),
        );
    }

    public function test_the_card_says_up_to_date_once_everything_ran(): void
    {
        file_put_contents($this->dir . '/20260101_000000_a.sql', 'SELECT 1');
        $this->runner()->run();
        $this->login('boss');

        $this->http->get('/admin/system-health/w/migrations')->assertOk()
            ->assertSee('Up to date')
            ->assertSee('20260101_000000_a.sql');
    }

    public function test_the_card_names_what_is_pending_after_a_deploy_that_skipped_it(): void
    {
        file_put_contents($this->dir . '/20260101_000000_a.sql', 'SELECT 1');
        $this->runner()->run();
        file_put_contents($this->dir . '/20261001_000000_add_x.sql', 'SELECT 1');
        $this->login('boss');

        $this->http->get('/admin/system-health/w/migrations')->assertOk()
            ->assertSee('1 pending')
            ->assertSee('run ./hydra migrate:run')
            ->assertSee('20261001_000000_add_x.sql');
    }

    public function test_only_an_admin_may_read_it(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/system-health/w/migrations')->assertStatus(403);
    }

    private function runner(): MigrationRunner
    {
        return $this->app->get(MigrationRunner::class);
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
