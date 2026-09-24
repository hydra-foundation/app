<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\RecoveryCodes;
use Hydra\Auth\Totp;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Signing in to an account with a second factor: the password alone opens
 * nothing, and a code or a recovery code finishes the job once.
 */
#[CoversNothing]
final class TwoFactorLoginFlowTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TestApp $app;

    private Client $http;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $id = $this->app->seed('clerk', Role::User);
        $this->app->seed('boss', Role::Admin);

        ['codes' => $this->codes, 'hashes' => $hashes] = $this->app->get(RecoveryCodes::class)->generate();
        $user = $this->app->get(UserRepository::class)->byIdentifier($id);
        $this->app->get(TwoFactorStoreInterface::class)->enable($user, self::SECRET, $hashes);

        $this->http = $this->app->http();
    }

    public function test_the_password_alone_asks_for_a_code_and_signs_nobody_in(): void
    {
        $this->app->login('clerk')->assertRedirect('/two-factor');

        $this->http->get('/two-factor')->assertOk()->assertSee('Code from your authenticator app');
        $this->http->get('/admin')->assertRedirect('/login');
    }

    public function test_an_account_without_one_signs_in_as_before(): void
    {
        $this->app->login('boss')->assertRedirect('/admin');
    }

    public function test_the_right_code_signs_in(): void
    {
        $this->app->login('clerk');

        $this->http->post('/two-factor', ['code' => $this->code()])->assertRedirect('/admin');
        $this->http->get('/admin')->assertRedirect('/admin/dashboard');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->app->login('clerk');

        $this->http->post('/two-factor', ['code' => '000000'])
            ->assertStatus(422)
            ->assertSee('That code is not valid');
        $this->http->get('/admin')->assertRedirect('/login');
    }

    public function test_a_code_works_once(): void
    {
        $this->app->login('clerk');
        $this->http->post('/two-factor', ['code' => $this->code()]);
        $this->http->post('/logout');

        $this->app->login('clerk');

        $this->http->post('/two-factor', ['code' => $this->code()])->assertStatus(422);
    }

    public function test_a_recovery_code_signs_in_once_and_is_announced(): void
    {
        $this->app->login('clerk');
        $this->http->get('/two-factor?recovery=1')->assertOk()->assertSee('Recovery code');

        $this->http->post('/two-factor', ['code_type' => 'recovery', 'code' => strtoupper($this->codes[3])])
            ->assertRedirect('/admin');
        $this->app->work();
        $this->app->mailer()->assertSentTo('clerk@example.com');
        $this->assertStringContainsString('9 recovery codes left', (string) $this->app->mailer()->sent()[0]->getText());
        $this->http->post('/logout');

        $this->app->login('clerk');
        $this->http->post('/two-factor', ['code_type' => 'recovery', 'code' => $this->codes[3]])
            ->assertStatus(422)
            ->assertSee('has been used');
    }

    public function test_each_step_is_logged(): void
    {
        $this->app->login('clerk');
        $this->http->post('/two-factor', ['code' => '000000']);
        $this->http->post('/two-factor', ['code_type' => 'recovery', 'code' => $this->codes[0]]);

        $messages = $this->app->log()->messages();

        $this->assertContains('auth.two_factor_challenged', $messages);
        $this->assertContains('auth.two_factor_failed', $messages);
        $this->assertContains('auth.recovery_code_used', $messages);
    }

    public function test_the_budget_for_codes_runs_out(): void
    {
        $this->app->login('clerk');

        for ($i = 0; $i < 5; $i++) {
            $this->http->post('/two-factor', ['code' => '000000'])->assertStatus(422);
        }

        $this->http->post('/two-factor', ['code' => $this->code()])->assertStatus(429);
    }

    public function test_nothing_pending_sends_back_to_the_password(): void
    {
        $this->http->get('/two-factor')->assertRedirect('/login');
        $this->http->post('/two-factor', ['code' => $this->code()])->assertRedirect('/login');
    }

    public function test_cancelling_forgets_the_password_step(): void
    {
        $this->app->login('clerk');

        $this->http->post('/two-factor/cancel')->assertRedirect('/login');

        $this->http->post('/two-factor', ['code' => $this->code()])->assertRedirect('/login');
        $this->http->get('/admin')->assertRedirect('/login');
    }

    private function code(): string
    {
        return $this->app->get(Totp::class)->code(self::SECRET);
    }
}
