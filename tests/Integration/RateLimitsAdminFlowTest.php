<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Http\Testing\Client;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Who a rate limit is refusing right now, and a way to let them back in: the
 * answer to "it says too many attempts" that used to be a wait.
 */
#[CoversNothing]
final class RateLimitsAdminFlowTest extends TestCase
{
    private const ATTACKER = '203.0.113.7';

    private TestApp $app;
    private Client $http;
    private LockoutStoreInterface $lockouts;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->lockouts = $this->app->get(LockoutStoreInterface::class);
    }

    public function test_a_client_past_the_sign_in_budget_is_listed_with_time_left(): void
    {
        $this->tripTheSignInThrottle();
        $this->login('boss');
        $body = $this->body('/admin/rate-limits');

        $this->assertStringContainsString('<title>Rate limits · Admin</title>', $body);
        $this->assertStringContainsString('>Sign-in attempts from one address</td>', $body);
        $this->assertStringContainsString('>' . self::ATTACKER . '</td>', $body);
        $this->assertStringContainsString('>5 per 10 minutes</td>', $body);
        $this->assertMatchesRegularExpression('~<time[^>]*>in 10 minutes</time>~', $body);
    }

    public function test_the_entry_sits_under_administration_after_access(): void
    {
        $this->login('boss');

        $this->assertMatchesRegularExpression('~href="/admin/users".*?href="/admin/access".*?href="/admin/rate-limits"~s', $this->body('/admin/rate-limits'));
    }

    public function test_letting_a_client_back_in_ends_its_lockout_at_once(): void
    {
        $this->tripTheSignInThrottle();
        $this->login('boss');

        $this->http->post('/admin/rate-limits/' . self::id('login', self::ATTACKER) . '/delete')->assertStatus(302);

        $this->assertNull($this->lockouts->find('login', self::ATTACKER));
        $this->assertNotSame(429, $this->attempt()->status());
    }

    public function test_the_row_offers_let_back_in(): void
    {
        $this->tripTheSignInThrottle();
        $this->login('boss');
        $body = $this->body('/admin/rate-limits');

        $this->assertStringContainsString('/admin/rate-limits/' . self::id('login', self::ATTACKER) . '/delete', $body);
        $this->assertStringContainsString('>Let back in</button>', $body);
    }

    public function test_a_limit_counted_by_account_names_the_account(): void
    {
        $this->lock('account-password', '2');
        $this->login('boss');
        $body = $this->body('/admin/rate-limits');

        $this->assertStringContainsString('>Password checks in Settings</td>', $body);
        $this->assertStringContainsString('>clerk</td>', $body);
    }

    public function test_a_limit_nobody_labelled_shows_its_own_name(): void
    {
        $this->lock('checkout-coupons', '198.51.100.9');
        $this->login('boss');

        $this->assertStringContainsString('>checkout-coupons</td>', $this->body('/admin/rate-limits'));
    }

    /** An address ending in .com at the end of a path is a file to nginx. */
    public function test_a_row_id_never_carries_the_identity_as_it_is(): void
    {
        $this->lock('password-reset-address', 'alice@example.com');
        $this->login('boss');
        $id = self::id('password-reset-address', 'alice@example.com');

        $this->assertStringNotContainsString('.', $id);
        $this->assertStringContainsString('alice@example.com', $this->body("/admin/rate-limits/{$id}"));
    }

    public function test_an_ended_or_malformed_lockout_says_it_has_ended(): void
    {
        $this->login('boss');

        foreach ([self::id('login', '192.0.2.1'), 'bm90LWEtbG9ja291dA', '%21%21'] as $id) {
            $this->http->htmx('div#admin-frame')->get("/admin/rate-limits/{$id}")
                ->assertStatus(404)->assertSee('That lockout has already ended.');
        }
    }

    /**
     * A lockout under a one-minute limit can end seconds after it began, which
     * is gone before anyone looks. Ended ones stay listed for a quarter of an
     * hour, marked, and can't be let back in: they already are.
     */
    public function test_a_lockout_that_ended_lately_is_listed_as_ended(): void
    {
        $this->lockouts->record(new Lockout('global', '192.0.2.1', new DateTimeImmutable('-11 minutes'), new DateTimeImmutable('-10 minutes'), 120, 60));
        $this->login('boss');
        $body = $this->body('/admin/rate-limits');

        $this->assertStringContainsString('>192.0.2.1</td>', $body);
        $this->assertStringContainsString('>Ended</td>', $body);
        $this->assertStringNotContainsString('/admin/rate-limits/' . self::id('global', '192.0.2.1') . '/delete', $body);
        $this->http->post('/admin/rate-limits/' . self::id('global', '192.0.2.1') . '/delete')
            ->assertStatus(422)->assertSee('That lockout has already ended.');
    }

    public function test_one_that_is_still_in_force_says_so(): void
    {
        $this->lock('login', '192.0.2.44');
        $this->login('boss');

        $this->assertStringContainsString('>Locked out</td>', $this->body('/admin/rate-limits'));
    }

    public function test_a_lockout_that_ended_over_a_quarter_of_an_hour_ago_is_not_listed(): void
    {
        $this->lockouts->record(new Lockout('login', '192.0.2.1', new DateTimeImmutable('-30 minutes'), new DateTimeImmutable('-16 minutes'), 5, 600));
        $this->login('boss');

        $this->assertStringNotContainsString('>192.0.2.1</td>', $this->body('/admin/rate-limits'));
        $this->http->htmx('div#admin-frame')->get('/admin/rate-limits/' . self::id('login', '192.0.2.1'))->assertStatus(404);
    }

    public function test_the_lockouts_still_in_force_come_first(): void
    {
        $this->lockouts->record(new Lockout('global', '192.0.2.1', new DateTimeImmutable('-2 minutes'), new DateTimeImmutable('-1 minute'), 120, 60));
        $this->lock('login', '192.0.2.44');
        $this->login('boss');
        $body = $this->body('/admin/rate-limits');

        $this->assertLessThan(strpos($body, '>192.0.2.1</td>'), strpos($body, '>192.0.2.44</td>'));
    }

    public function test_the_links_split_sign_in_limits_from_the_rest(): void
    {
        $this->lock('login-account', 'clerk');
        $this->lock('global', '198.51.100.9');
        $this->login('boss');

        $signIn = $this->body('/admin/rate-limits?view=sign-in');
        $other = $this->body('/admin/rate-limits?view=everything-else');

        $this->assertStringContainsString('>Sign-in attempts on one account</td>', $signIn);
        $this->assertStringNotContainsString('>198.51.100.9</td>', $signIn);
        $this->assertStringContainsString('>198.51.100.9</td>', $other);
        $this->assertStringNotContainsString('>Sign-in attempts on one account</td>', $other);
    }

    public function test_a_search_finds_by_who(): void
    {
        $this->lock('global', '198.51.100.9');
        $this->lock('login', '192.0.2.44');
        $this->login('boss');

        $body = $this->body('/admin/rate-limits?q=198.51');

        $this->assertStringContainsString('>198.51.100.9</td>', $body);
        $this->assertStringNotContainsString('>192.0.2.44</td>', $body);
    }

    public function test_letting_a_client_back_in_is_audited(): void
    {
        $this->lock('login', '192.0.2.44');
        $this->login('boss');

        $this->http->post('/admin/rate-limits/' . self::id('login', '192.0.2.44') . '/delete')->assertStatus(302);

        $this->assertSame(
            [['module' => 'rate-limits', 'message' => 'admin.row_deleted', 'old_value' => '{"policy":"login","identity":"192.0.2.44"}']],
            $this->app->db()->select('SELECT module, message, old_value FROM audit'),
        );
    }

    public function test_rate_limits_are_admin_only(): void
    {
        $this->lock('login', '192.0.2.44');
        $this->login('clerk');

        $this->http->get('/admin/rate-limits')->assertStatus(403);
        $this->http->post('/admin/rate-limits/' . self::id('login', '192.0.2.44') . '/delete')->assertStatus(403);
        $this->assertNotNull($this->lockouts->find('login', '192.0.2.44'));
    }

    /** Five wrong passwords from one address are the budget; the sixth is refused. */
    private function tripTheSignInThrottle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->attempt()->status());
        }

        $this->assertSame(429, $this->attempt()->status());
    }

    private function attempt(): \Hydra\Http\Testing\TestResponse
    {
        return $this->app->http()->from(self::ATTACKER)->post('/login', ['username' => 'clerk', 'password' => 'not-it']);
    }

    private function lock(string $policy, string $identity): void
    {
        $this->lockouts->record(new Lockout($policy, $identity, new DateTimeImmutable, new DateTimeImmutable('+10 minutes'), 5, 600));
    }

    private static function id(string $policy, string $identity): string
    {
        return rtrim(strtr(base64_encode("{$policy}:{$identity}"), '+/', '-_'), '=');
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
