<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** The dashboard's user figures follow new users without a reload. */
#[CoversNothing]
final class LiveDashboardFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->login('boss');
    }

    public function test_the_totals_strip_listens_on_users(): void
    {
        $this->app->http()->get('/admin/dashboard/w/totals')->assertOk()
            ->assertSee('data-stream="module.users"')
            ->assertSee('sse:module.users delay:500ms');
    }

    public function test_the_newest_accounts_card_listens_on_users(): void
    {
        $this->app->http()->get('/admin/dashboard/w/newest')->assertOk()
            ->assertSee('data-stream="module.users"')
            ->assertSee('sse:module.users delay:500ms');
    }

    public function test_a_card_that_does_not_list_users_does_not_listen(): void
    {
        $this->app->http()->get('/admin/dashboard/w/changes')->assertOk()->assertDontSee('data-stream');
    }
}
