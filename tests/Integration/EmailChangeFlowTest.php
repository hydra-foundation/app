<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Mail\Message;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The signed-in user moving their own address: asked for in Settings, applied
 * only from the link sent to the new address, and announced to the old one.
 */
#[CoversNothing]
final class EmailChangeFlowTest extends TestCase
{
    private const NEW_EMAIL = 'clerk@elsewhere.example';

    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->app->login('clerk')->assertStatus(302);
    }

    public function test_asking_mails_the_new_address_and_changes_nothing_yet(): void
    {
        $this->ask()->assertOk()->assertSee('A link is on its way to ' . self::NEW_EMAIL);

        $this->app->work();
        $this->app->mailer()->assertSent(times: 1);
        $this->app->mailer()->assertSentTo(self::NEW_EMAIL);
        $this->assertSame('clerk@example.com', $this->email());
    }

    public function test_the_link_moves_the_address_verifies_it_and_warns_the_old_one(): void
    {
        $link = $this->requestLink();

        $this->http->get($link)->assertRedirect('/change-email');
        $this->http->get('/change-email')->assertOk()->assertSee('Your account now uses ' . self::NEW_EMAIL);

        $this->assertSame(self::NEW_EMAIL, $this->email());
        $this->assertNotNull($this->app->db()->selectOne("SELECT email_verified_at FROM users WHERE username = 'clerk'")['email_verified_at']);

        $this->app->work();
        $warning = $this->app->mailer()->sent(static fn (Message $m): bool => $m->isFor('clerk@example.com'));
        $this->assertCount(1, $warning);
        $this->assertStringContainsString(self::NEW_EMAIL, (string) $warning[0]->getText());
    }

    public function test_the_change_is_audited(): void
    {
        $this->http->get($this->requestLink());
        $this->http->get('/change-email');

        $rows = $this->app->db()->select('SELECT module, username, message FROM audit');

        $this->assertSame([['module' => 'users', 'username' => 'clerk', 'message' => 'account.email_changed']], $rows);
    }

    public function test_the_link_works_once(): void
    {
        $link = $this->requestLink();

        $this->http->get($link);
        $this->http->get('/change-email')->assertOk();
        $this->http->get($link);
        $this->http->get('/change-email')->assertStatus(404)->assertSee('Link expired');
    }

    public function test_a_password_change_cancels_the_link(): void
    {
        $link = $this->requestLink();

        $this->http->post('/admin/settings/account', [
            'current_password' => TestApp::PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->http->get($link);
        $this->http->get('/change-email')->assertStatus(404);
        $this->assertSame('clerk@example.com', $this->email());
    }

    public function test_an_address_taken_after_the_link_was_sent_is_refused(): void
    {
        $link = $this->requestLink();
        $this->app->db()->execute("UPDATE users SET email = ? WHERE username = 'boss'", [self::NEW_EMAIL]);

        $this->http->get($link);
        $this->http->get('/change-email')->assertStatus(409)->assertSee('Address in use');
        $this->assertSame('clerk@example.com', $this->email());
    }

    public function test_a_wrong_current_password_queues_nothing(): void
    {
        $this->ask(current: 'not-it')->assertStatus(422)->assertSee('That is not your current password.');

        $this->assertSame(0, $this->app->queued());
    }

    public function test_the_same_or_a_taken_address_is_refused(): void
    {
        $this->ask('Clerk@Example.com')->assertStatus(422)->assertSee('That is already your address.');
        $this->ask('boss@example.com')->assertStatus(422)->assertSee('That email address is already in use.');
        $this->ask('not-an-address')->assertStatus(422);

        $this->assertSame(0, $this->app->queued());
    }

    public function test_both_forms_spend_one_budget(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->ask(current: 'guess-' . $i)->assertStatus(422);
        }

        $this->http->post('/admin/settings/account', [
            'current_password' => 'guess-4',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertStatus(422);

        $this->ask()->assertStatus(429);
    }

    public function test_the_token_never_reaches_the_activity_log(): void
    {
        $link = $this->requestLink();
        $token = substr($link, strlen('/change-email/'));

        $this->http->get($link);
        $this->http->get('/change-email');

        $rows = $this->app->pdo()->query('SELECT path, query, referer FROM activity')->fetchAll();

        $this->assertNotEmpty($rows);
        $this->assertStringNotContainsString($token, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function ask(string $email = self::NEW_EMAIL, string $current = TestApp::PASSWORD): TestResponse
    {
        return $this->http->post('/admin/settings/account', [
            'intent' => 'email',
            'email' => $email,
            'current_password' => $current,
        ]);
    }

    /** Asks for the move and returns the path of the link it mails. */
    private function requestLink(): string
    {
        $this->ask()->assertOk();
        $this->app->work();

        $text = (string) $this->app->mailer()->sent()[0]->getText();
        preg_match('#/change-email/[A-Za-z0-9_-]+#', $text, $match);

        return $match[0] ?? self::fail('No link in the mail: ' . $text);
    }

    private function email(): string
    {
        return (string) $this->app->db()->selectOne("SELECT email FROM users WHERE username = 'clerk'")['email'];
    }
}
