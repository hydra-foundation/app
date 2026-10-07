<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\SignIn;
use Hydra\Http\Testing\Client;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Every browser signed in, whoever's it is: the tab beside API tokens under
 * Access, for "someone else is in my account". Only live sign-ins are listed,
 * and the admin's own is marked and cannot be revoked from here.
 */
#[CoversNothing]
final class SessionsAdminFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;
    private SignInStoreInterface $signIns;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->app->seed('alice', Role::User);

        $this->http = $this->app->http();
        $this->signIns = $this->app->get(SignInStoreInterface::class);
    }

    public function test_every_live_sign_in_is_listed_with_where_it_was_seen(): void
    {
        $this->signIn('alice', 'a', '203.0.113.7', 'Firefox/140 (X11; Linux)');
        $this->signIn('clerk', 'c', '198.51.100.2', 'Safari/18');
        $this->login('boss');
        $body = $this->body('/admin/sessions');

        $this->assertStringContainsString('<title>Sessions · Admin</title>', $body);
        $this->assertStringContainsString('>alice</td>', $body);
        $this->assertStringContainsString('>203.0.113.7</td>', $body);
        $this->assertStringContainsString('Firefox/140', $body);
        $this->assertStringContainsString('>clerk</td>', $body);
        $this->assertStringContainsString('>198.51.100.2</td>', $body);
    }

    public function test_sign_ins_narrow_to_the_days_they_began_on(): void
    {
        $old = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->signIn('clerk', 'c', '198.51.100.2', 'Safari');
        $this->app->db()->execute('UPDATE sign_ins SET created_at = ? WHERE id = ?', [(new DateTimeImmutable('2026-01-05 12:00 UTC'))->getTimestamp(), $old->id]);
        $this->login('boss');

        $january = $this->body('/admin/sessions?created_at_from=2026-01-01&created_at_to=2026-01-31');

        $this->assertStringContainsString('>alice</td>', $january);
        $this->assertStringNotContainsString('>clerk</td>', $january);
        $this->assertStringContainsString('name="created_at_from"', $january);
    }

    public function test_a_sign_in_idle_past_the_window_is_not_listed(): void
    {
        $this->signIn('alice', 'a', '203.0.113.7', 'Firefox', new DateTimeImmutable('-2 hours'));
        $this->login('boss');

        $this->assertStringNotContainsString('>alice</td>', $this->body('/admin/sessions'));
    }

    public function test_the_admins_own_sign_in_is_marked(): void
    {
        $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('boss');
        $body = $this->body('/admin/sessions');

        $this->assertSame(1, substr_count($body, 'This is you'));
        $this->assertMatchesRegularExpression('~>boss</td>.*?This is you~s', $body);
    }

    public function test_the_strip_names_both_lists_under_the_access_entry(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/sessions');

        $this->assertMatchesRegularExpression('~<a class="admin-tab"\s+href="/admin/access"[^>]*>API tokens</a>~', $body);
        $this->assertMatchesRegularExpression('~<a class="admin-tab active"\s+href="/admin/sessions"[^>]*>Sessions</a>~', $body);
        $this->assertMatchesRegularExpression('~class="admin-tabs"[^>]*aria-label="Access"~', $body);
        $this->assertStringNotContainsString('href="/admin/sessions" class="nav', $body);
    }

    public function test_a_search_matches_the_owner_their_address_and_the_ip(): void
    {
        $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->signIn('clerk', 'c', '198.51.100.2', 'Safari');
        $this->login('boss');

        $byName = $this->body('/admin/sessions?q=alice');
        $byEmail = $this->body('/admin/sessions?q=clerk%40example');
        $byIp = $this->body('/admin/sessions?q=198.51.100');

        $this->assertStringContainsString('>alice</td>', $byName);
        $this->assertStringNotContainsString('>clerk</td>', $byName);
        $this->assertStringContainsString('>clerk</td>', $byEmail);
        $this->assertStringContainsString('>clerk</td>', $byIp);
        $this->assertStringNotContainsString('>alice</td>', $byIp);
    }

    public function test_a_sign_in_shows_the_whole_agent_and_the_owners_address(): void
    {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0 with a long tail that a list would cut';
        $signIn = $this->signIn('alice', 'a', '203.0.113.7', $agent);
        $this->login('boss');

        $show = $this->body("/admin/sessions/{$signIn->id}");

        $this->assertStringContainsString($agent, $show);
        $this->assertStringContainsString('alice@example.com', $show);
        $this->assertStringContainsString('&hellip;</span>', $this->body('/admin/sessions'), 'cut short on the list, whole on hover');
    }

    public function test_a_sign_in_that_ended_while_the_list_was_open_says_so(): void
    {
        $this->login('boss');

        $this->http->htmx('div#admin-frame')->get('/admin/sessions/' . str_repeat('9', 32))
            ->assertStatus(404)->assertSee('That sign-in has ended.');
    }

    public function test_sessions_are_admin_only(): void
    {
        $signIn = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('clerk');

        $this->http->get('/admin/sessions')->assertStatus(403);
        $this->http->get("/admin/sessions/{$signIn->id}")->assertStatus(403);
    }

    public function test_a_revoked_sign_in_is_signed_out_on_its_next_request(): void
    {
        [$elsewhere, $session] = $this->browserSignedIn('alice');
        $this->login('boss');

        $this->http->post("/admin/sessions/{$elsewhere}/delete")->assertStatus(302);

        $this->assertNull($this->signIns->find($elsewhere));
        $this->assertNull($this->guardOver($session)->user());
    }

    public function test_every_other_sign_in_offers_a_revoke_and_the_admins_own_does_not(): void
    {
        $other = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('boss');
        $mine = $this->mine();
        $body = $this->body('/admin/sessions');

        $this->assertStringContainsString("/admin/sessions/{$other->id}/delete", $body);
        $this->assertStringNotContainsString("/admin/sessions/{$mine}/delete", $body);
        $this->assertStringContainsString('>Revoke</button>', $body);
    }

    public function test_the_admins_own_sign_in_is_refused_by_the_server_too(): void
    {
        $this->login('boss');
        $mine = $this->mine();

        $this->http->post("/admin/sessions/{$mine}/delete")
            ->assertStatus(422)->assertSee('That is your own sign-in; use Sign out instead.');

        $this->assertNotNull($this->signIns->find($mine));
    }

    public function test_revoking_a_sign_in_twice_says_it_has_already_ended(): void
    {
        $other = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('boss');
        $this->http->post("/admin/sessions/{$other->id}/delete")->assertStatus(302);

        $this->http->post("/admin/sessions/{$other->id}/delete")
            ->assertStatus(422)->assertSee('That sign-in has already ended.');
    }

    public function test_a_revoke_is_audited_by_whose_it_was_and_where_from(): void
    {
        $other = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('boss');

        $this->http->post("/admin/sessions/{$other->id}/delete")->assertStatus(302);

        $rows = $this->app->db()->select('SELECT module, table_id, message, old_value FROM audit');
        $this->assertSame(
            [['module' => 'sessions', 'table_id' => $other->id, 'message' => 'admin.row_deleted', 'old_value' => '{"owner":"alice","ip":"203.0.113.7"}']],
            $rows,
        );
    }

    public function test_only_an_admin_may_revoke_a_sign_in(): void
    {
        $other = $this->signIn('alice', 'a', '203.0.113.7', 'Firefox');
        $this->login('clerk');

        $this->http->post("/admin/sessions/{$other->id}/delete")->assertStatus(403);
        $this->assertNotNull($this->signIns->find($other->id));
    }

    /**
     * A second browser, signed in for real: its own session, and the guard a
     * request of its would build.
     *
     * @return array{0: string, 1: ArraySessionStore}
     */
    private function browserSignedIn(string $username): array
    {
        $session = new ArraySessionStore;
        $session->start();
        $guard = $this->guardOver($session);
        $guard->login($this->user($username));

        return [$guard->signIn()->id ?? throw new RuntimeException('not recorded'), $session];
    }

    private function guardOver(ArraySessionStore $session): SessionGuard
    {
        return new SessionGuard(
            $session,
            $this->app->get(UserProviderInterface::class),
            $this->app->get(HasherInterface::class),
            null,
            $this->signIns,
            $this->app->get(ClockInterface::class),
        );
    }

    /** The sign-in the admin is making these requests with. */
    private function mine(): string
    {
        return $this->app->get(SessionGuard::class)->signIn()->id ?? throw new RuntimeException('not signed in');
    }

    private function signIn(string $username, string $seed, string $ip, string $agent, ?DateTimeImmutable $at = null): SignIn
    {
        $at ??= new DateTimeImmutable;
        $id = str_pad($seed, 32, '0');
        $this->signIns->create($id, $this->user($username), $at);
        $this->signIns->touch($id, $at, $ip, $agent);

        return $this->signIns->find($id) ?? throw new RuntimeException('not written');
    }

    private function user(string $username): AuthenticatableInterface
    {
        $id = $this->app->db()->selectOne('SELECT id FROM users WHERE username = ?', [$username])['id'] ?? throw new RuntimeException("{$username} is not seeded");

        return $this->app->get(UserProviderInterface::class)->byIdentifier((int) $id) ?? throw new RuntimeException("{$username} is not seeded");
    }

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
