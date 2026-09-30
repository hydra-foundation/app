<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Broadcast\Hub\HubReport;
use Hydra\Broadcast\Testing\FakeHubStatus;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Database\MigrationRunner;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
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

    private const ATTACKER = '203.0.113.7';

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

    public function test_the_live_updates_card_comes_after_migrations(): void
    {
        $this->login('boss');

        $this->assertMatchesRegularExpression(
            '~id="admin-widget-migrations".*?id="admin-widget-sse"~s',
            $this->http->get('/admin/system-health')->assertOk()->body(),
        );
    }

    public function test_the_live_updates_card_reads_the_hubs_report(): void
    {
        $this->app->get(FakeHubStatus::class)->publish(new HubReport(7, time() - 60, 4, true, time()), 30);
        $this->login('boss');

        $this->http->get('/admin/system-health/w/sse')->assertOk()
            ->assertSee('Running')
            ->assertSee('Streams');
    }

    public function test_only_an_admin_may_read_it(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/system-health/w/migrations')->assertStatus(403);
    }

    public function test_the_cache_card_offers_flush_with_a_warning_about_rate_limits(): void
    {
        $this->sharedCache();
        $this->login('boss');

        $this->http->get('/admin/system-health/w/cache')->assertOk()
            ->assertSee('hx-post="/admin/system-health/w/cache/flush"')
            ->assertSee('hx-confirm="Empty the cache? This also lets everyone who is rate limited back in."')
            ->assertSee('>Flush</button>');
    }

    public function test_flush_lets_a_locked_out_client_straight_back_in_and_rate_limits_agrees(): void
    {
        $this->sharedCache();
        $this->tripTheSignInThrottle();
        $this->login('boss');

        $this->frame()->post('/admin/system-health/w/cache/flush')->assertOk()
            ->assertSee('Cache flushed. 1 rate-limited client was let back in.');

        $this->assertSame([], $this->app->get(LockoutStoreInterface::class)->active(new \DateTimeImmutable));
        $this->assertMatchesRegularExpression('~' . preg_quote(self::ATTACKER, '~') . '.*?Ended~s', $this->http->get('/admin/rate-limits')->assertOk()->body());
        $this->assertNotSame(429, $this->attempt()->status());
    }

    public function test_a_flush_is_audited(): void
    {
        $this->sharedCache();
        $this->login('boss');

        $this->frame()->post('/admin/system-health/w/cache/flush')->assertOk();

        $this->assertSame(
            [['module' => 'system-health', 'table_id' => 'cache.flush', 'username' => 'boss']],
            $this->app->db()->select("SELECT module, table_id, username FROM audit WHERE module = 'system-health'"),
        );
    }

    public function test_a_per_worker_cache_is_refused_on_the_card(): void
    {
        $this->app->container()->instance(CacheConfig::class, new CacheConfig(CacheConfig::ARRAY));
        $this->login('boss');

        $this->frame()->post('/admin/system-health/w/cache/flush')->assertStatus(422)
            ->assertSee('there is no shared cache to flush');
    }

    public function test_only_an_admin_may_flush(): void
    {
        $this->sharedCache();
        $this->app->get(StoreInterface::class)->put('kept', 'yes');
        $this->login('clerk');

        $this->frame()->post('/admin/system-health/w/cache/flush')->assertStatus(403);
        $this->assertSame('yes', $this->app->get(StoreInterface::class)->get('kept'));
    }

    public function test_a_flush_without_the_form_s_token_is_refused(): void
    {
        $this->sharedCache();
        $this->app->get(StoreInterface::class)->put('kept', 'yes');
        $this->login('boss');

        $status = $this->http->unprepared()->post('/admin/system-health/w/cache/flush')->status();

        $this->assertContains($status, [403, 419]);
        $this->assertSame('yes', $this->app->get(StoreInterface::class)->get('kept'));
    }

    /**
     * The suite's store is an ArrayStore behind no CacheConfig; the card and
     * the flush are about a shared one, so they are told it is Redis.
     */
    private function sharedCache(): void
    {
        $this->app->container()->instance(CacheConfig::class, new CacheConfig(CacheConfig::REDIS, host: '127.0.0.1', port: 1, prefix: 'test:', timeout: 0.05, readTimeout: 0.05));
    }

    private function tripTheSignInThrottle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->attempt()->status());
        }

        $this->assertSame(429, $this->attempt()->status());
    }

    private function attempt(): TestResponse
    {
        return $this->app->http()->from(self::ATTACKER)->post('/login', ['username' => 'clerk', 'password' => 'not-it']);
    }

    private function frame(): Client
    {
        return $this->http->htmx('div#admin-frame');
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
