<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** The bell in the skeleton: in the shell, counting and listing each user's own notices. */
#[CoversNothing]
final class NotificationsFlowTest extends TestCase
{
    private TestApp $app;
    private int $boss;
    private int $clerk;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->boss = $this->app->seed('boss', Role::Admin);
        $this->clerk = $this->app->seed('clerk', Role::User);
    }

    public function test_the_admin_shell_rings_the_bell(): void
    {
        $this->app->login('boss');

        $this->app->http()->get('/admin/dashboard')->assertOk()
            ->assertSee('class="admin-bell dropdown"')
            ->assertSee('hx-get="/admin/notifications/badge?place=sidebar"');
    }

    public function test_a_user_who_is_not_an_admin_still_has_a_bell(): void
    {
        $this->app->login('clerk');

        $this->app->http()->get('/admin/settings/account')->assertOk()->assertSee('class="admin-bell dropdown"');
    }

    public function test_the_badge_and_the_list_are_the_signed_in_users_own(): void
    {
        $notifier = $this->app->get(Notifier::class);
        $notifier->notify($this->boss, new Notice('For the boss', url: '/admin/users'));
        $notifier->notify($this->clerk, new Notice('For the clerk'));
        $this->app->login('boss');

        $this->app->http()->get('/admin/notifications/badge')->assertOk()->assertSee('1 unread notification');
        $this->app->http()->get('/admin/notifications')->assertOk()->assertSee('For the boss')->assertDontSee('For the clerk');
    }

    public function test_the_notifier_tells_the_users_pages(): void
    {
        $this->app->get(Notifier::class)->notify($this->boss, new Notice('Hello'));

        $this->app->get(FakeBroadcaster::class)->assertPublished('user.' . $this->boss, 'notification', times: 1);
    }
}
