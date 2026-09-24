<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Testing\Client;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Message;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Forgot password, end to end: the answer that does not say whether an
 * account exists, the link that leaves its token in the session and not the
 * URL, and the password change that spends it.
 */
#[CoversNothing]
final class PasswordResetFlowTest extends TestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    private TestApp $app;

    private Client $http;

    private FrozenClock $clock;

    private int $id;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->id = $this->app->seed('clerk', Role::User);

        // Before anything resolves the token service, which holds the clock.
        $this->clock = new FrozenClock;
        $this->app->container()->instance(ClockInterface::class, $this->clock);

        $this->http = $this->app->http();
    }

    public function test_a_known_and_an_unknown_address_get_the_same_answer(): void
    {
        $known = $this->http->post('/forgot-password', ['email' => 'clerk@example.com']);
        $unknown = $this->http->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($this->withoutCsrf($known->body()), $this->withoutCsrf($unknown->body()));
        // Both queue a job, so neither request does work the other skips.
        $this->assertSame(2, $this->app->queued());
        $this->app->mailer()->assertNothingSent();

        $this->assertSame(2, $this->app->work());
        $this->app->mailer()->assertSent(times: 1);
        $this->app->mailer()->assertSentTo('clerk@example.com');
    }

    public function test_the_link_resets_the_password_once(): void
    {
        $link = $this->requestLink();

        $this->http->get($link)->assertRedirect('/reset-password');
        $this->http->get('/reset-password')->assertOk()->assertSee('Choose a new password');

        $this->http->post('/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $this->http->get('/login')->assertSee('Your password has been reset');
        $this->app->login('clerk', self::NEW_PASSWORD)->assertRedirect('/admin');

        $this->http->post('/logout');
        $this->http->get($link)->assertRedirect('/reset-password');
        $this->http->get('/reset-password')->assertStatus(404)->assertSee('Link expired');
    }

    public function test_only_a_link_that_was_sent_is_logged(): void
    {
        $this->http->post('/forgot-password', ['email' => 'clerk@example.com']);
        $this->http->post('/forgot-password', ['email' => 'nobody@example.com']);
        $this->app->work();

        $sent = array_values(array_filter(
            $this->app->log()->records(),
            static fn (array $record): bool => $record['message'] === 'auth.reset_link_sent',
        ));

        $this->assertCount(1, $sent);
        $this->assertSame(['user' => $this->id], $sent[0]['context']);
    }

    public function test_a_reset_is_logged(): void
    {
        $this->http->get($this->requestLink());
        $this->http->post('/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $this->assertSame(
            ['level' => 'notice', 'message' => 'auth.password_reset', 'context' => ['user' => $this->id]],
            $this->app->log()->firstWith('auth.password_reset'),
        );
    }

    public function test_a_mismatched_confirmation_changes_nothing(): void
    {
        $this->http->get($this->requestLink());

        $this->http->post('/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'something-else-entirely',
        ])->assertStatus(422)->assertSee('The passwords do not match.');

        $this->app->login('clerk')->assertRedirect('/admin');
        $this->assertFalse($this->app->log()->has('auth.password_reset'));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->requestLink();

        $this->clock->advance('+3601 seconds');

        $this->http->get($link);
        $this->http->get('/reset-password')->assertStatus(404);
        $this->http->post('/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(404);
    }

    public function test_the_form_without_a_link_is_refused(): void
    {
        $this->http->get('/reset-password')->assertStatus(404);
    }

    public function test_the_token_never_reaches_the_activity_log(): void
    {
        $link = $this->requestLink();
        $token = substr($link, strlen('/reset-password/'));

        $this->http->get($link);
        $this->http->get('/reset-password');
        $this->http->post('/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $rows = $this->app->pdo()->query('SELECT path, query, referer FROM activity')->fetchAll();

        $this->assertNotEmpty($rows);
        $this->assertStringNotContainsString($token, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_one_address_gets_three_links_an_hour_and_the_fourth_answer_is_the_same(): void
    {
        $answers = [];

        foreach (range(1, 4) as $_) {
            $answers[] = $this->withoutCsrf($this->http->post('/forgot-password', ['email' => 'clerk@example.com'])->body());
        }

        $this->app->work();
        $this->app->mailer()->assertSent(times: 3);
        $this->assertCount(1, array_unique($answers));
    }

    public function test_one_client_is_refused_after_five_requests(): void
    {
        foreach (range(1, 5) as $i) {
            $this->http->post('/forgot-password', ['email' => "someone{$i}@example.com"])->assertOk();
        }

        $this->http->post('/forgot-password', ['email' => 'someone6@example.com'])->assertStatus(429);
    }

    public function test_a_mail_failure_is_retried_by_the_worker_and_never_reaches_the_answer(): void
    {
        $this->app->container()->instance(MailerInterface::class, new class implements MailerInterface {
            public function send(Message $message): void
            {
                throw new TransportException('SMTP is down');
            }
        });

        $this->http->post('/forgot-password', ['email' => 'clerk@example.com'])
            ->assertOk()
            ->assertSee('a reset link is on its way');

        $this->app->work();

        $this->assertTrue($this->app->log()->has(
            'Queued job App\Jobs\SendPasswordResetLink failed on attempt 1 of 3 and will be tried again in 10 seconds: SMTP is down',
        ));
        $this->assertSame(1, $this->app->queued());
    }

    public function test_the_queued_job_holds_the_address_and_never_a_link(): void
    {
        $this->http->post('/forgot-password', ['email' => 'clerk@example.com']);

        $this->assertSame(
            [['job' => 'App\Jobs\SendPasswordResetLink', 'payload' => '{"email":"clerk@example.com"}']],
            $this->app->db()->select('SELECT job, payload FROM jobs'),
        );
    }

    public function test_a_malformed_address_is_sent_back(): void
    {
        $this->http->post('/forgot-password', ['email' => 'not-an-address'])
            ->assertStatus(422)
            ->assertSee('Enter a valid email address.');

        $this->assertSame(0, $this->app->queued());
    }

    /** Asks for a link for the seeded account and returns its path. */
    private function requestLink(): string
    {
        $this->http->post('/forgot-password', ['email' => 'clerk@example.com']);
        $this->app->work();

        $text = (string) $this->app->mailer()->sent()[0]->getText();

        $this->assertSame(1, preg_match('#(?:https?://[^/\s]+)?(/reset-password/[A-Za-z0-9_-]+)#', $text, $match));

        return $match[1];
    }

    /** Each render carries a fresh CSRF field; everything else must match. */
    private function withoutCsrf(string $body): string
    {
        return (string) preg_replace('/name="_token" value="[^"]*"|nonce="[^"]*"/', '', $body);
    }
}
