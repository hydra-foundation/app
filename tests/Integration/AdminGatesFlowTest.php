<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a signed-in user who is not an admin can reach. The dashboard is open,
 * so /admin lands somewhere, but every card on it reads an admin's table, and
 * System Health says what the install has not been patched against.
 */
#[CoversNothing]
final class AdminGatesFlowTest extends TestCase
{
    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
    }

    /** @return iterable<string, array{string}> */
    public static function cards(): iterable
    {
        foreach (['totals', 'traffic', 'paths', 'newest', 'changes', 'queue'] as $card) {
            yield $card => [$card];
        }
    }

    #[DataProvider('cards')]
    public function test_a_standard_user_is_refused_every_dashboard_card(string $card): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->assertStringNotContainsString("/admin/dashboard/w/{$card}", $this->http->get('/admin/dashboard')->assertOk()->body());
        $this->http->get("/admin/dashboard/w/{$card}")->assertStatus(403);
    }

    #[DataProvider('cards')]
    public function test_an_admin_gets_every_dashboard_card(string $card): void
    {
        $this->app->login('boss')->assertStatus(302);

        $this->assertStringContainsString("/admin/dashboard/w/{$card}", $this->http->get('/admin/dashboard')->assertOk()->body());
        $this->http->get("/admin/dashboard/w/{$card}")->assertOk();
    }

    public function test_a_standard_user_is_refused_system_health(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->http->get('/admin/system-health')->assertStatus(403);
    }

    public function test_an_admin_gets_system_health(): void
    {
        $this->app->login('boss')->assertStatus(302);

        $this->http->get('/admin/system-health')->assertOk();
    }
}
