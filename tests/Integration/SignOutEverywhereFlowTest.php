<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every way into one account, ended in one action: each browser signed in and
 * each API token. From the user's row under Users, or from any of their
 * sign-ins under Sessions. On the admin's own account the sign-in doing it is
 * kept, as a password change keeps it.
 */
#[CoversNothing]
final class SignOutEverywhereFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;
    private SignInStoreInterface $signIns;
    private ApiTokenStoreInterface $tokens;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->app->seed('alice', Role::User);

        $this->http = $this->app->http();
        $this->signIns = $this->app->get(SignInStoreInterface::class);
        $this->tokens = $this->app->get(ApiTokenStoreInterface::class);
    }

    public function test_each_user_row_offers_it_and_asks_first(): void
    {
        $this->login('boss');
        $body = $this->http->get('/admin/users')->assertOk()->body();

        $this->assertStringContainsString('hx-post="/admin/users/3/sign-out-everywhere', $body);
        $this->assertStringContainsString('hx-confirm="Sign this person out of every browser and revoke every API token they have?"', $body);
    }

    public function test_from_users_every_sign_in_and_token_of_that_user_goes_and_nobody_elses(): void
    {
        $this->signInAs('alice', 'a1');
        $this->signInAs('alice', 'a2');
        $this->issue('alice');
        $this->issue('alice');
        $this->issue('alice');
        $this->signInAs('clerk', 'c1');
        $this->issue('clerk');
        $this->login('boss');

        $this->frame()->post('/admin/users/3/sign-out-everywhere')
            ->assertOk()->assertSee('alice is signed out everywhere: 2 sign-ins and 3 API tokens revoked.');

        $this->assertSame([], $this->signIns->forUser($this->user('alice')));
        $this->assertSame([], $this->tokens->forUser($this->user('alice')));
        $this->assertCount(1, $this->signIns->forUser($this->user('clerk')));
        $this->assertCount(1, $this->tokens->forUser($this->user('clerk')));
    }

    public function test_one_of_each_is_said_in_the_singular(): void
    {
        $this->signInAs('alice', 'a1');
        $this->issue('alice');
        $this->login('boss');

        $this->frame()->post('/admin/users/3/sign-out-everywhere')
            ->assertOk()->assertSee('alice is signed out everywhere: 1 sign-in and 1 API token revoked.');
    }

    public function test_nothing_to_revoke_says_so(): void
    {
        $this->login('boss');

        $this->frame()->post('/admin/users/3/sign-out-everywhere')
            ->assertOk()->assertSee('alice had no sign-ins or API tokens to revoke.');
    }

    public function test_from_a_sessions_row_its_owner_is_signed_out_everywhere(): void
    {
        $row = $this->signInAs('alice', 'a1');
        $this->signInAs('alice', 'a2');
        $this->issue('alice');
        $this->login('boss');

        $this->frame()->post("/admin/sessions/{$row}/sign-out-everywhere")
            ->assertOk()->assertSee('alice is signed out everywhere: 2 sign-ins and 1 API token revoked.');

        $this->assertSame([], $this->signIns->forUser($this->user('alice')));
        $this->assertSame([], $this->tokens->forUser($this->user('alice')));
    }

    public function test_a_sessions_row_already_gone_says_so(): void
    {
        $row = $this->signInAs('alice', 'a1');
        $this->signIns->revoke($row);
        $this->login('boss');

        $this->frame()->post("/admin/sessions/{$row}/sign-out-everywhere")
            ->assertStatus(422)->assertSee('That sign-in has already ended.');
    }

    public function test_on_your_own_account_this_sign_in_is_kept_and_every_other_way_in_goes(): void
    {
        $this->signInAs('boss', 'b1');
        $this->issue('boss');
        $this->login('boss');
        $mine = $this->app->get(SessionGuard::class)->signIn()->id ?? self::fail('not signed in');

        $this->frame()->post('/admin/users/1/sign-out-everywhere')
            ->assertOk()->assertSee('You are signed out everywhere else: 1 other sign-in and 1 API token revoked.');

        $left = $this->signIns->forUser($this->user('boss'));
        $this->assertCount(1, $left);
        $this->assertSame($mine, $left[0]->id);
        $this->assertSame([], $this->tokens->forUser($this->user('boss')));
        $this->assertSame(1, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_on_your_own_account_with_nothing_else_it_says_so(): void
    {
        $this->login('boss');

        $this->frame()->post('/admin/users/1/sign-out-everywhere')
            ->assertOk()->assertSee('You had no other sign-ins or API tokens to revoke.');
        $this->assertSame(1, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_a_user_deleted_meanwhile_says_so(): void
    {
        $this->login('boss');

        $this->frame()->post('/admin/users/99/sign-out-everywhere')->assertStatus(422)->assertSee('That user no longer exists.');
    }

    public function test_both_are_audited(): void
    {
        $row = $this->signInAs('alice', 'a1');
        $this->login('boss');

        $this->frame()->post("/admin/sessions/{$row}/sign-out-everywhere")->assertOk();
        $this->frame()->post('/admin/users/2/sign-out-everywhere')->assertOk();

        $this->assertSame(
            [
                ['module' => 'sessions', 'table_id' => $row, 'message' => 'admin.action: sign-out-everywhere'],
                ['module' => 'users', 'table_id' => '2', 'message' => 'admin.action: sign-out-everywhere'],
            ],
            $this->app->db()->select('SELECT module, table_id, message FROM audit ORDER BY id'),
        );
    }

    public function test_only_an_admin_may_do_either(): void
    {
        $row = $this->signInAs('alice', 'a1');
        $this->login('clerk');

        $this->frame()->post('/admin/users/3/sign-out-everywhere')->assertStatus(403);
        $this->frame()->post("/admin/sessions/{$row}/sign-out-everywhere")->assertStatus(403);
        $this->assertCount(1, $this->signIns->forUser($this->user('alice')));
    }

    /** A sign-in recorded for $username, seen now; returns its id. */
    private function signInAs(string $username, string $seed): string
    {
        $id = str_pad($seed, 32, '0');
        $this->signIns->create($id, $this->user($username), new DateTimeImmutable);

        return $id;
    }

    private function issue(string $username): void
    {
        $this->app->get(ApiTokens::class)->issue($this->user($username), 'CI');
    }

    private function user(string $username): AuthenticatableInterface
    {
        $id = $this->app->db()->selectOne('SELECT id FROM users WHERE username = ?', [$username])['id'] ?? throw new RuntimeException("{$username} is not seeded");

        return $this->app->get(UserProviderInterface::class)->byIdentifier((int) $id) ?? throw new RuntimeException("{$username} is not seeded");
    }

    private function frame(): Client
    {
        return $this->http->htmx('div#admin-frame');
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
