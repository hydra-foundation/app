<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The mail an admin write sends on its own: a verification link to a new
 * account, and to an account whose address moved.
 */
#[CoversNothing]
final class AddressMailFlowTest extends TestCase
{
    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->app->login('boss');
    }

    public function test_a_created_account_is_sent_a_link(): void
    {
        $this->http->post('/admin/users/new', [
            'username' => 'newcomer',
            'email' => 'newcomer@example.com',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        $this->app->work();
        $this->app->mailer()->assertSent(times: 1);
        $this->app->mailer()->assertSentTo('newcomer@example.com');
    }

    public function test_a_moved_address_is_sent_a_link(): void
    {
        $this->edit('clerk@elsewhere.example');

        $this->app->work();
        $this->app->mailer()->assertSent(times: 1);
        $this->app->mailer()->assertSentTo('clerk@elsewhere.example');
    }

    public function test_an_edit_that_keeps_the_address_sends_nothing(): void
    {
        $this->edit('clerk@example.com', role: 'admin');

        $this->assertSame(0, $this->app->queued());
    }

    public function test_a_refused_write_sends_nothing(): void
    {
        $this->edit('boss@example.com')->assertStatus(422);

        $this->assertSame(0, $this->app->queued());
    }

    private function edit(string $email, string $role = 'user'): TestResponse
    {
        return $this->http->post('/admin/users/2/edit', [
            'username' => 'clerk',
            'email' => $email,
            'role' => $role,
            'password' => '',
        ]);
    }
}
