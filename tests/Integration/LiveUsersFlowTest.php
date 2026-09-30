<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The Users list is live: it listens on module.users, a write in the admin
 * publishes there, and only an admin is granted the topic.
 */
#[CoversNothing]
final class LiveUsersFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
    }

    public function test_the_users_list_listens_on_its_module(): void
    {
        $this->app->login('boss');

        $this->app->http()->get('/admin/users')->assertOk()->assertSeeInOrder([
            'data-stream="module.users"',
            'hx-get="/admin/users?',
            '_live=1"',
            'hx-trigger="sse:module.users delay:500ms"',
            'hx-target="#admin-body"',
        ]);
    }

    public function test_creating_a_user_in_the_admin_publishes_on_module_users(): void
    {
        $this->app->login('boss');

        $this->app->http()->post('/admin/users/new', [
            'username' => 'newcomer',
            'email' => 'newcomer@example.com',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        $this->app->get(FakeBroadcaster::class)->assertPublished(
            'module.users',
            'changed',
            static fn (Envelope $e): bool => ($e->data['action'] ?? null) === 'admin.row_created',
        );
    }

    public function test_a_user_made_outside_the_admin_is_published_too(): void
    {
        // Resolved as make:user and the settings screens resolve it. Built by
        // hand, the repository was always handed the broadcaster; autowired,
        // it once was not.
        $this->app->get(UserRepository::class)->create('outsider', 'outsider@example.com', 'x');
        $this->app->get(UserProviderInterface::class);

        $this->app->get(FakeBroadcaster::class)->assertPublished('module.users', 'changed', times: 1);
        $this->assertSame($this->app->get(UserRepository::class), $this->app->get(UserProviderInterface::class));
    }

    public function test_an_admin_is_granted_the_users_topic(): void
    {
        $this->app->login('boss');

        $this->app->http()->get('/stream/token?topics=module.users', ['Accept' => 'application/json'])->assertOk();
    }

    public function test_a_user_who_cannot_open_users_is_refused_its_topic(): void
    {
        $this->app->login('clerk');

        $response = $this->app->http()->get('/stream/token?topics=module.users', ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertStringContainsString('module.users', $response->body());
    }

    public function test_an_unknown_module_is_refused(): void
    {
        $this->app->login('boss');

        $this->app->http()->get('/stream/token?topics=module.nothing', ['Accept' => 'application/json'])->assertStatus(403);
    }
}
