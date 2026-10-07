<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\Sources\AccessTokenSource;
use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
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

    public function test_the_strip_names_the_tokens_list_and_the_heading_names_the_family(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/access');

        $this->assertMatchesRegularExpression('~<a class="admin-tab active"\s+href="/admin/access"[^>]*>API tokens</a>~', $body);
        $this->assertMatchesRegularExpression('~<a class="admin-tab"\s+href="/admin/sessions"[^>]*>Sessions</a>~', $body);
        $this->assertStringContainsString('<title>Access · Admin</title>', $body);
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

    public function test_tokens_narrow_to_the_days_they_were_made_on(): void
    {
        $old = $this->issue('alice', 'Made in January');
        $this->issue('alice', 'Made today');
        $this->app->db()->execute('UPDATE api_tokens SET created_at = ? WHERE id = ?', [(new DateTimeImmutable('2026-01-05 12:00 UTC'))->getTimestamp(), $old->token->id]);
        $this->login('boss');

        $january = $this->body('/admin/access?created_at_from=2026-01-01&created_at_to=2026-01-31');

        $this->assertStringContainsString('>Made in January</td>', $january);
        $this->assertStringNotContainsString('>Made today</td>', $january);
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

    public function test_a_pasted_secret_is_not_kept_in_the_activity_log(): void
    {
        $leaked = $this->issue('alice', 'Leaked one');
        $this->login('boss');

        $this->body('/admin/access?q=' . $leaked->plain);

        $recorded = json_encode($this->app->db()->select('SELECT query, referer FROM activity'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('q=hyd_\\u2026', $recorded);
        $this->assertStringNotContainsString($leaked->plain, $recorded);
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

        $this->frame()->get('/admin/access/99')->assertStatus(404)->assertSee('That token has been revoked.');
    }

    public function test_access_is_admin_only(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('clerk');

        $this->http->get('/admin/access')->assertStatus(403);
        $this->http->get("/admin/access/{$issued->token->id}")->assertStatus(403);
    }

    public function test_a_revoked_token_no_longer_gets_into_the_api(): void
    {
        $revoked = $this->issue('alice', 'Leaked one');
        $kept = $this->issue('alice', 'Still hers');
        $this->login('boss');

        $this->http->post("/admin/access/{$revoked->token->id}/delete")->assertStatus(302);

        $tokens = $this->app->get(ApiTokens::class);
        $this->assertNull($tokens->authenticate($revoked->plain));
        $this->assertNotNull($tokens->authenticate($kept->plain));
    }

    public function test_the_row_offers_a_revoke_rather_than_a_delete(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');
        $body = $this->body('/admin/access');

        $this->assertStringContainsString("/admin/access/{$issued->token->id}/delete", $body);
        $this->assertStringContainsString('>Revoke</button>', $body);
    }

    public function test_a_revoke_is_audited_by_owner_and_name_and_nothing_secret(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');

        $this->http->post("/admin/access/{$issued->token->id}/delete")->assertStatus(302);

        $rows = $this->app->db()->select('SELECT * FROM audit');
        $this->assertCount(1, $rows);
        $this->assertSame('access', $rows[0]['module']);
        $this->assertSame((string) $issued->token->id, $rows[0]['table_id']);
        $this->assertSame('admin.row_deleted', $rows[0]['message']);
        $this->assertSame('{"owner":"alice","name":"Laptop"}', $rows[0]['old_value']);

        $all = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($issued->token->hash, $all);
        $this->assertStringNotContainsString($issued->plain, $all);
    }

    public function test_revoking_a_token_twice_says_it_is_already_gone(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');
        $this->http->post("/admin/access/{$issued->token->id}/delete")->assertStatus(302);

        $this->http->post("/admin/access/{$issued->token->id}/delete")->assertStatus(422)->assertSee('That token has already been revoked.');
    }

    /** Deleting a user cascades to their tokens, so a stale row may have no owner either. */
    public function test_a_token_whose_owner_was_deleted_is_already_revoked(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->app->db()->execute("DELETE FROM users WHERE username = 'alice'");

        try {
            $this->app->get(AccessTokenSource::class)->delete((string) $issued->token->id);
            self::fail('A token with no owner was revoked.');
        } catch (WriteRejected $e) {
            $this->assertSame(['id' => 'That token has already been revoked.'], $e->errors());
        }
    }

    public function test_only_an_admin_may_revoke(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('clerk');

        $this->http->post("/admin/access/{$issued->token->id}/delete")->assertStatus(403);
        $this->assertNotNull($this->app->get(ApiTokens::class)->authenticate($issued->plain));
    }

    public function test_each_token_offers_to_revoke_all_of_its_owners_and_asks_first(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');
        $body = $this->body('/admin/access');

        $this->assertStringContainsString("hx-post=\"/admin/access/{$issued->token->id}/revoke-owner", $body);
        $this->assertStringContainsString("hx-confirm=\"Revoke every API token this token&#039;s owner has?\"", $body);
    }

    public function test_revoking_all_of_an_owners_tokens_leaves_everyone_elses(): void
    {
        $first = $this->issue('alice', 'Laptop');
        $second = $this->issue('alice', 'Phone');
        $third = $this->issue('alice', 'CI');
        $theirs = $this->issue('clerk', 'Clerk CI');
        $this->login('boss');

        $this->frame()->post("/admin/access/{$first->token->id}/revoke-owner")->assertOk()->assertSee('All 3 of alice&#039;s API tokens revoked.');

        $tokens = $this->app->get(ApiTokens::class);
        foreach ([$first, $second, $third] as $issued) {
            $this->assertNull($tokens->authenticate($issued->plain));
        }
        $this->assertNotNull($tokens->authenticate($theirs->plain));
    }

    public function test_an_owner_with_one_token_is_told_so(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');

        $this->frame()->post("/admin/access/{$issued->token->id}/revoke-owner")->assertOk()->assertSee('alice&#039;s one API token revoked.');
    }

    public function test_revoking_all_of_an_owners_tokens_is_audited(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('boss');

        $this->frame()->post("/admin/access/{$issued->token->id}/revoke-owner")->assertOk();

        $rows = $this->app->db()->select('SELECT module, table_id, message FROM audit');
        $this->assertSame([['module' => 'access', 'table_id' => (string) $issued->token->id, 'message' => 'admin.action: revoke-owner']], $rows);
    }

    public function test_revoking_all_from_a_token_already_gone_says_so(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $kept = $this->issue('alice', 'Phone');
        $this->app->get(ApiTokenStoreInterface::class)->revoke($this->user('alice'), $issued->token->id);
        $this->login('boss');

        $this->frame()->post("/admin/access/{$issued->token->id}/revoke-owner")->assertStatus(422)->assertSee('That token has already been revoked.');
        $this->assertNotNull($this->app->get(ApiTokens::class)->authenticate($kept->plain));
    }

    public function test_only_an_admin_may_revoke_all_of_an_owners_tokens(): void
    {
        $issued = $this->issue('alice', 'Laptop');
        $this->login('clerk');

        $this->frame()->post("/admin/access/{$issued->token->id}/revoke-owner")->assertStatus(403);
        $this->assertNotNull($this->app->get(ApiTokens::class)->authenticate($issued->plain));
    }

    private function issue(string $username, string $name, ?DateTimeImmutable $expires = null): IssuedApiToken
    {
        // Issued on the real clock, so an expiry is "in the future" to it.
        return (new ApiTokens(
            $this->app->get(ApiTokenStoreInterface::class),
            $this->app->get(UserProviderInterface::class),
            new FrozenClock('now'),
        ))->issue($this->user($username), $name, $expires);
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

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
