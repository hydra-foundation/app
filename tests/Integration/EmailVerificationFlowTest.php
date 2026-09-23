<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Entities\User;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Verification end to end: the banner that asks, the link that answers
 * without a session, and the address change that spends it.
 */
#[CoversNothing]
final class EmailVerificationFlowTest extends TestCase
{
    private TestApp $app;

    private Client $http;

    private FrozenClock $clock;

    private int $id;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->id = $this->app->seed('clerk', Role::User);

        $this->clock = new FrozenClock;
        $this->app->container()->instance(ClockInterface::class, $this->clock);

        $this->http = $this->app->http();
        $this->app->login('clerk');
    }

    public function test_an_unverified_user_is_asked_to_verify(): void
    {
        $this->admin()->assertOk()->assertSee('clerk@example.com is not verified yet.');
    }

    public function test_the_link_verifies_the_address_without_a_session(): void
    {
        // The mail client that opens the link is often not the signed-in browser.
        $link = $this->requestLink();
        $this->http->post('/logout');

        $this->http->get($link)->assertRedirect('/verify-email');
        $this->http->get('/verify-email')->assertOk()->assertSee('Email verified');

        $this->assertTrue($this->user()->hasVerifiedEmail());
        $this->app->login('clerk');
        $this->admin()->assertOk()->assertDontSee('not verified yet');
    }

    public function test_a_second_click_reads_as_done(): void
    {
        $link = $this->requestLink();

        $this->http->get($link);
        $this->http->get('/verify-email')->assertOk();
        $this->http->get($link);
        $this->http->get('/verify-email')->assertOk()->assertSee('Email verified');
    }

    public function test_changing_the_address_spends_the_link(): void
    {
        $link = $this->requestLink();

        $this->app->pdo()->exec("UPDATE users SET email = 'clerk@elsewhere.test' WHERE id = {$this->id}");

        $this->http->get($link);
        $this->http->get('/verify-email')->assertStatus(404)->assertSee('Link expired');
        $this->assertFalse($this->user()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->requestLink();

        $this->clock->advance('+86401 seconds');

        $this->http->get($link);
        $this->http->get('/verify-email')->assertStatus(404);
    }

    public function test_the_banner_says_the_link_was_sent(): void
    {
        $this->http->post('/verify-email')->assertRedirect('/admin');

        $this->admin()->assertSee('A verification link is on its way to clerk@example.com.');
    }

    public function test_three_links_an_hour_and_then_the_banner_says_why(): void
    {
        foreach (range(1, 4) as $_) {
            $this->http->post('/verify-email');
        }

        $this->app->work();
        $this->app->mailer()->assertSent(times: 3);
        $this->admin()->assertSee('A link was sent recently.');
    }

    public function test_sending_needs_a_signed_in_user(): void
    {
        $this->http->post('/logout');

        $this->http->post('/verify-email')->assertRedirect('/login');
        $this->assertSame(0, $this->app->queued());
    }

    public function test_a_verified_user_is_sent_nothing(): void
    {
        $this->assertTrue($this->app->get(UserRepository::class)->markVerified($this->id, 'clerk@example.com'));
        $this->http->post('/logout');
        $this->app->login('clerk');

        $this->http->post('/verify-email');

        $this->assertSame(0, $this->app->queued());
    }

    public function test_the_token_never_reaches_the_activity_log(): void
    {
        $link = $this->requestLink();

        $this->http->get($link);
        $this->http->get('/verify-email');

        $rows = $this->app->pdo()->query('SELECT path, query, referer FROM activity')->fetchAll();

        $this->assertStringNotContainsString(
            substr($link, strlen('/verify-email/')),
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }

    /** The status line is shown once, so a test reads it once. */
    private function admin(): TestResponse
    {
        return $this->http->followingRedirects()->get('/admin');
    }

    private function requestLink(): string
    {
        $this->http->post('/verify-email');
        $this->app->work();

        $text = (string) $this->app->mailer()->sent()[0]->getText();

        $this->assertSame(1, preg_match('#(?:https?://[^/\s]+)?(/verify-email/[A-Za-z0-9_-]+)#', $text, $match));

        return $match[1];
    }

    private function user(): User
    {
        return $this->app->get(UserRepository::class)->byIdentifier($this->id)
            ?? throw new \LogicException('The seeded user is gone.');
    }
}
