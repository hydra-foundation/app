<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use RuntimeException;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A password change ends every session the account had, except the one that
 * made it. Asserted through {@see TestApp::nextGuard()}: the app's own guard
 * holds its user for the whole test and would never look again.
 */
#[CoversNothing]
final class PasswordChangeSessionsFlowTest extends TestCase
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

    public function test_a_change_made_elsewhere_signs_this_session_out(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->app->db()->execute(
            "UPDATE users SET password_hash = ? WHERE username = 'clerk'",
            [$this->app->get(HasherInterface::class)->hash('changed-somewhere-else')],
        );

        $this->assertNull($this->app->nextGuard()->user());
    }

    public function test_changing_your_own_password_keeps_you_signed_in(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->http->post('/admin/settings/account', [
            'current_password' => TestApp::PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_an_admin_setting_their_own_password_through_users_stays_signed_in(): void
    {
        $this->app->login('boss')->assertStatus(302);

        $this->http->post('/admin/users/1/edit', [
            'username' => 'boss',
            'email' => 'boss@example.com',
            'role' => 'admin',
            'password' => 'a-brand-new-passphrase',
        ]);

        $this->assertSame(1, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_an_edit_that_leaves_the_password_alone_changes_nothing(): void
    {
        $this->app->login('boss')->assertStatus(302);

        $this->http->post('/admin/users/1/edit', [
            'username' => 'boss',
            'email' => 'boss@example.com',
            'role' => 'admin',
            'password' => '',
        ]);

        $this->assertSame(1, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_changing_your_own_password_revokes_your_api_tokens(): void
    {
        $this->issueToken(2);
        $this->app->login('clerk')->assertStatus(302);

        $this->http->post('/admin/settings/account', [
            'current_password' => TestApp::PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertSame([], $this->tokens(2));
    }

    public function test_an_admin_setting_someone_s_password_revokes_their_api_tokens(): void
    {
        $this->issueToken(2);
        $this->app->login('boss')->assertStatus(302);

        $this->http->post('/admin/users/2/edit', [
            'username' => 'clerk',
            'email' => 'clerk@example.com',
            'role' => 'user',
            'password' => 'a-brand-new-passphrase',
        ]);

        $this->assertSame([], $this->tokens(2));
    }

    public function test_an_edit_that_leaves_the_password_alone_keeps_the_tokens(): void
    {
        $this->issueToken(2);
        $this->app->login('boss')->assertStatus(302);

        $this->http->post('/admin/users/2/edit', [
            'username' => 'clerk',
            'email' => 'clerk@example.com',
            'role' => 'user',
            'password' => '',
        ]);

        $this->assertCount(1, $this->tokens(2));
    }

    private function issueToken(int $id): void
    {
        $this->app->get(ApiTokens::class)->issue($this->user($id), 'CI');
    }

    /** @return list<\Hydra\Auth\ApiToken> */
    private function tokens(int $id): array
    {
        return $this->app->get(ApiTokenStoreInterface::class)->forUser($this->user($id));
    }

    private function user(int $id): \Hydra\Auth\Contracts\AuthenticatableInterface
    {
        return $this->app->get(UserProviderInterface::class)->byIdentifier($id) ?? throw new RuntimeException('not seeded');
    }
}
