<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\IssuedApiToken;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Every API token, whoever owns it: what an admin reaches for when a token
 * leaks and all they hold is the secret. The secret and its hash stay out of
 * every page the admin draws.
 */
#[CoversNothing]
final class AccessAdminFlowTest extends TestCase
{
    private const NOW = '2026-09-29 12:00:00';

    private TestApp $app;
    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->app->seed('alice', Role::User);
        $this->app->container()->instance(ClockInterface::class, new FrozenClock(self::NOW));

        $this->http = $this->app->http();
    }

    public function test_every_users_tokens_are_listed_with_their_owner(): void
    {
        $this->issue('alice', 'Alice laptop');
        $this->issue('clerk', 'Clerk CI');
        $this->login('boss');
        $body = $this->body('/admin/access');

        $this->assertStringContainsString('<title>Access · Admin</title>', $body);
        $this->assertStringContainsString('>Alice laptop</td>', $body);
        $this->assertStringContainsString('>alice</td>', $body);
        $this->assertStringContainsString('>Clerk CI</td>', $body);
        $this->assertStringContainsString('>clerk</td>', $body);
        $this->assertStringContainsString('>Active</td>', $body);
    }

    public function test_access_sits_under_administration_after_users(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/access');

        $this->assertMatchesRegularExpression('~href="/admin/users".*?href="/admin/access"~s', $body);
        $this->assertStringContainsString('bi-key', $body);
    }

    public function test_a_time_that_never_came_says_never(): void
    {
        $this->issue('alice', 'Forever');
        $this->login('boss');

        $this->assertStringContainsString('>Never</td>', $this->body('/admin/access'));
    }

    /**
     * Expired by the app's clock, not the database's: the frozen clock sits
     * past an expiry the database's own clock has not reached, so the
     * list and authenticate() agree on which tokens still work.
     */
    public function test_the_state_links_split_live_tokens_from_expired_ones(): void
    {
        $this->issue('alice', 'Still good', new DateTimeImmutable('+30 days'));
        $this->issue('alice', 'Ran out', new DateTimeImmutable('+1 hour'));
        $this->app->container()->instance(ClockInterface::class, new FrozenClock('+2 days'));
        $this->login('boss');

        $active = $this->body('/admin/access?view=active');
        $expired = $this->body('/admin/access?view=expired');

        $this->assertStringContainsString('>Still good</td>', $active);
        $this->assertStringNotContainsString('>Ran out</td>', $active);
        $this->assertStringContainsString('>Ran out</td>', $expired);
        $this->assertStringNotContainsString('>Still good</td>', $expired);
        $this->assertStringContainsString('>Expired</td>', $expired);
    }

    public function test_the_list_sorts_by_when_a_token_was_last_used(): void
    {
        $old = $this->issue('alice', 'Used long ago');
        $recent = $this->issue('alice', 'Used just now');
        $this->app->db()->execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [1_000_000_000, $old->token->id]);
        $this->app->db()->execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [1_700_000_000, $recent->token->id]);
        $this->login('boss');

        $body = $this->body('/admin/access?sort=last_used_at&dir=desc');

        $this->assertLessThan(strpos($body, 'Used long ago'), strpos($body, 'Used just now'));
    }

    public function test_an_unknown_sort_falls_back_to_the_newest_first(): void
    {
        $this->issue('alice', 'First');
        $this->issue('alice', 'Second');
        $this->login('boss');

        $body = $this->body('/admin/access?sort=token_hash');

        $this->assertLessThan(strpos($body, '>First<'), strpos($body, '>Second<'));
    }

    public function test_pasting_a_leaked_secret_finds_exactly_its_token(): void
    {
        $leaked = $this->issue('alice', 'Leaked one');
        $this->issue('alice', 'Another');
        $this->issue('clerk', 'Unrelated');
        $this->login('boss');

        $body = $this->body('/admin/access?q=' . urlencode($leaked->plain));

        $this->assertStringContainsString('>Leaked one</td>', $body);
        $this->assertStringNotContainsString('>Another</td>', $body);
        $this->assertStringNotContainsString('>Unrelated</td>', $body);
    }

    public function test_a_well_formed_secret_nobody_holds_finds_nothing(): void
    {
        $this->issue('alice', 'Alice laptop');
        $this->login('boss');

        $body = $this->body('/admin/access?q=hyd_' . str_repeat('a', 43));

        $this->assertStringNotContainsString('>Alice laptop</td>', $body);
    }

    public function test_a_search_matches_the_owner_and_the_token_name(): void
    {
        $this->issue('alice', 'Laptop');
        $this->issue('clerk', 'Deploy script');
        $this->login('boss');

        $byName = $this->body('/admin/access?q=alice');
        $byEmail = $this->body('/admin/access?q=clerk%40example');
        $byToken = $this->body('/admin/access?q=deploy');

        $this->assertStringContainsString('>Laptop</td>', $byName);
        $this->assertStringNotContainsString('>Deploy script</td>', $byName);
        $this->assertStringContainsString('>Deploy script</td>', $byEmail);
        $this->assertStringContainsString('>Deploy script</td>', $byToken);
        $this->assertStringNotContainsString('>Laptop</td>', $byToken);
    }

    public function test_a_piece_of_a_hash_finds_nothing(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');

        $body = $this->body('/admin/access?q=' . substr($issued->token->hash, 0, 12));

        $this->assertStringNotContainsString('>Laptop</td>', $body);
    }

    public function test_a_token_shows_its_owner_and_nothing_secret(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');

        $show = $this->body("/admin/access/{$issued->token->id}");
        $list = $this->body('/admin/access');

        $this->assertStringContainsString('alice@example.com', $show);
        $this->assertStringContainsString('Laptop', $show);

        foreach ([$show, $list] as $body) {
            $this->assertStringNotContainsString($issued->token->hash, $body);
            $this->assertStringNotContainsString($issued->plain, $body);
        }
    }

    public function test_a_token_revoked_while_the_list_was_open_says_so(): void
    {
        $this->login('boss');

        $this->http->htmx('div#admin-frame')->get('/admin/access/99')->assertStatus(404)->assertSee('That token has been revoked.');
    }

    public function test_access_is_admin_only(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('clerk');

        $this->http->get('/admin/access')->assertStatus(403);
        $this->http->get("/admin/access/{$issued->token->id}")->assertStatus(403);
    }

    private function issue(string $username, string $name, ?DateTimeImmutable $expires = null): IssuedApiToken
    {
        $id = $this->app->db()->selectOne('SELECT id FROM users WHERE username = ?', [$username])['id'] ?? throw new RuntimeException("{$username} is not seeded");
        $user = $this->app->get(UserProviderInterface::class)->byIdentifier((int) $id) ?? throw new RuntimeException("{$username} is not seeded");

        // Issued on the real clock, so an expiry is "in the future" to it.
        return (new ApiTokens(
            $this->app->get(ApiTokenStoreInterface::class),
            $this->app->get(UserProviderInterface::class),
            new FrozenClock('now'),
        ))->issue($user, $name, $expires);
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
