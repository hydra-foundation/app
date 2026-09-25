<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Entities\Role;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Auth\Totp;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Mail\Message;
use Hydra\Session\Contracts\SessionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Turning the second factor on and off from Settings, and replacing its
 * recovery codes: each change takes the password and a code, is audited, and
 * is mailed to the account's owner.
 */
#[CoversNothing]
final class TwoFactorSettingsFlowTest extends TestCase
{
    private const URL = '/admin/settings/security';

    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->app->login('clerk')->assertStatus(302);
    }

    public function test_it_starts_off_and_setup_shows_a_qr_code_and_the_key(): void
    {
        $this->http->get(self::URL)->assertOk()->assertSee('name="intent" value="start"');

        $response = $this->http->post(self::URL, ['intent' => 'start'])->assertOk();

        $secret = $this->setupSecret();
        $response->assertSee('data-qr="otpauth://totp/' . rawurlencode($this->appName()) . ':clerk?secret=' . $secret);
        $response->assertSee(implode(' ', str_split($secret, 4)));
        $this->assertNull($this->stored(), 'nothing is saved before a code confirms it');
    }

    public function test_confirming_takes_the_password(): void
    {
        $this->http->post(self::URL, ['intent' => 'start']);

        $this->http->post(self::URL, ['intent' => 'confirm', 'code' => $this->code(), 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertSee('That is not your current password.');
        $this->assertNull($this->stored());
    }

    public function test_confirming_takes_a_code_from_the_key_shown(): void
    {
        $this->http->post(self::URL, ['intent' => 'start']);

        $this->confirm('000000')->assertStatus(422)->assertSee('That code does not match');
        $this->assertNull($this->stored());
    }

    public function test_a_right_code_turns_it_on_and_shows_ten_recovery_codes_once(): void
    {
        $this->http->post(self::URL, ['intent' => 'start']);

        $response = $this->confirm($this->code())->assertOk()->assertSee('Two-factor sign in is on.');

        $this->assertCount(10, $this->recoveryCodes($response));
        $this->assertNotNull($this->stored());
        $this->assertNull($this->setupKey());

        $this->http->get(self::URL)->assertOk()->assertSee('10 left')->assertDontSee('Your recovery codes');
    }

    public function test_turning_it_on_is_audited_and_mailed(): void
    {
        $this->enable();

        $this->assertSame(['account.two_factor_enabled'], $this->auditMessages());

        $this->app->work();
        $this->app->mailer()->assertSentTo('clerk@example.com');
        $this->assertSame(
            ["Two-factor sign in is on for your {$this->appName()} account"],
            array_map(static fn (Message $m): string => (string) $m->getSubject(), $this->app->mailer()->sent()),
        );
    }

    public function test_the_code_that_confirmed_it_cannot_sign_in(): void
    {
        $code = $this->enable()['code'];
        $this->http->post('/logout');

        $this->app->login('clerk')->assertRedirect('/two-factor');
        $this->http->post('/two-factor', ['code' => $code])->assertStatus(422);
    }

    public function test_new_recovery_codes_replace_the_old(): void
    {
        $old = $this->enable()['codes'];

        $response = $this->http->post(self::URL, ['intent' => 'regenerate', 'code' => $this->nextCode(), 'current_password' => TestApp::PASSWORD])
            ->assertOk()
            ->assertSee('The old ones no longer work.');

        $new = $this->recoveryCodes($response);
        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));

        $this->http->post(self::URL, ['intent' => 'disable', 'code' => $old[0], 'current_password' => TestApp::PASSWORD])
            ->assertStatus(422)
            ->assertSee('That code is not valid, or has been used.');
        $this->assertContains('account.recovery_codes_regenerated', $this->auditMessages());
    }

    public function test_a_recovery_code_turns_it_off_and_both_are_mailed(): void
    {
        $codes = $this->enable()['codes'];
        $this->app->work();

        $this->http->post(self::URL, ['intent' => 'disable', 'code' => $codes[0], 'current_password' => TestApp::PASSWORD])
            ->assertOk()
            ->assertSee('Two-factor sign in is off.');

        $this->assertNull($this->stored());
        $this->assertSame(0, (int) $this->app->db()->selectOne('SELECT COUNT(*) AS n FROM user_recovery_codes')['n']);
        $this->assertContains('account.two_factor_disabled', $this->auditMessages());

        $this->app->work();
        $subjects = array_map(static fn (Message $m): string => (string) $m->getSubject(), $this->app->mailer()->sent());
        $this->assertContains("A recovery code was used on your {$this->appName()} account", $subjects);
        $this->assertContains("Two-factor sign in is off for your {$this->appName()} account", $subjects);

        $this->http->post('/logout');
        $this->app->login('clerk')->assertRedirect('/admin');
    }

    public function test_turning_it_off_takes_the_password_and_a_code(): void
    {
        $this->enable();

        $this->http->post(self::URL, ['intent' => 'disable', 'code' => $this->nextCode(), 'current_password' => 'wrong'])
            ->assertStatus(422);
        $response = $this->http->post(self::URL, ['intent' => 'disable', 'code' => '', 'current_password' => TestApp::PASSWORD])
            ->assertStatus(422)
            ->assertSee('Enter a code from your app, or a recovery code.');

        $this->assertSame(['Two-factor'], $this->openRows($response->body()));

        $this->assertNotNull($this->stored());
    }

    public function test_a_form_that_no_longer_applies_changes_nothing(): void
    {
        $this->http->post(self::URL, ['intent' => 'disable', 'code' => '123456', 'current_password' => TestApp::PASSWORD])
            ->assertOk()
            ->assertSee('That form is out of date.');

        $this->http->post(self::URL, ['intent' => 'confirm', 'code' => '123456', 'current_password' => TestApp::PASSWORD])
            ->assertOk()
            ->assertSee('That setup has expired.');
    }

    public function test_cancelling_setup_forgets_the_key(): void
    {
        $this->http->post(self::URL, ['intent' => 'start']);

        $this->http->post(self::URL, ['intent' => 'cancel'])->assertOk()->assertSee('name="intent" value="start"');

        $this->assertNull($this->setupKey());
    }

    public function test_the_policy_lets_the_qr_library_load(): void
    {
        $response = $this->http->get(self::URL)->assertOk()->assertSee('/js/qr.js');

        $this->assertStringContainsString('https://cdnjs.cloudflare.com', $response->header('Content-Security-Policy'));
    }

    /** Whatever the environment named the app: CI's config tests leave APP_NAME=x behind. */
    private function appName(): string
    {
        return $this->app->get(AppConfig::class)->name;
    }

    /** @return array{code: string, codes: list<string>} */
    private function enable(): array
    {
        $this->http->post(self::URL, ['intent' => 'start']);
        $code = $this->code();

        return ['code' => $code, 'codes' => $this->recoveryCodes($this->confirm($code)->assertOk())];
    }

    private function confirm(string $code): TestResponse
    {
        return $this->http->post(self::URL, ['intent' => 'confirm', 'code' => $code, 'current_password' => TestApp::PASSWORD]);
    }

    /** @return list<string> */
    private function recoveryCodes(TestResponse $response): array
    {
        preg_match_all('#<li>([a-z0-9]{5}-[a-z0-9]{5})</li>#', $response->body(), $matches);

        return $matches[1];
    }

    private function setupSecret(): string
    {
        return (string) $this->setupKey();
    }

    /** What the next request would find in the session. */
    private function setupKey(): mixed
    {
        $session = $this->app->get(SessionInterface::class);
        $session->start();

        return $session->get('_2fa_setup');
    }

    private function code(): string
    {
        return $this->app->get(Totp::class)->code($this->setupSecret() ?: $this->decrypted());
    }

    /** One step ahead, which the drift allows, so it is not the step already claimed. */
    private function nextCode(): string
    {
        return $this->app->get(Totp::class)->code($this->decrypted(), intdiv(time(), 30) + 1);
    }

    private function decrypted(): string
    {
        $user = $this->app->get(UserRepository::class)->byUsername('clerk');

        return (string) $this->app->get(TwoFactorRepository::class)->secret($user);
    }

    private function stored(): ?string
    {
        return $this->app->db()->selectOne("SELECT two_factor_secret FROM users WHERE username = 'clerk'")['two_factor_secret'];
    }

    /** @return list<string> */
    private function auditMessages(): array
    {
        return array_column($this->app->db()->select('SELECT message FROM audit ORDER BY id'), 'message');
    }

    /** @return list<string> */
    private function openRows(string $body): array
    {
        preg_match_all('~<details[^>]*\sopen>\s*<summary>\s*<span class="settings-row-label">([^<]+)~', $body, $m);

        return $m[1];
    }
}
