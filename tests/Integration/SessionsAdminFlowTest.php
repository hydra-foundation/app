<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\SignIn;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
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
