<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AccountFlowTest extends TestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->app->login('clerk')->assertStatus(302);
    }

    public function test_the_account_screen_names_the_signed_in_user(): void
    {
        $this->http->get('/admin/settings/account')
            ->assertOk()
            ->assertSee('clerk@example.com')
            ->assertSee('not verified')
            ->assertSee('class="settings-tab active"');
    }

    public function test_the_new_password_signs_in_and_the_old_one_does_not(): void
    {
        $this->change()->assertOk()->assertSee('Saved');

        // Still signed in on this browser after the session id moved.
        $this->http->get('/admin/settings/account')->assertOk();

        $this->http->post('/logout');
        $this->app->login('clerk')->assertStatus(422);
        $this->app->login('clerk', self::NEW_PASSWORD)->assertRedirect('/admin');
    }

    public function test_the_change_is_audited_without_the_password(): void
    {
        $this->change()->assertOk();

        $rows = $this->app->db()->select('SELECT * FROM audit');

        $this->assertCount(1, $rows);
        $this->assertSame('users', $rows[0]['module']);
        $this->assertSame('clerk', $rows[0]['username']);
        $this->assertSame('account.password_changed', $rows[0]['message']);
        $this->assertNull($rows[0]['old_value']);
        $this->assertNull($rows[0]['new_value']);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_a_refused_change_is_not_audited(): void
    {
        $this->change(current: 'not-it')->assertStatus(422);

        $this->assertSame([], $this->app->db()->select('SELECT * FROM audit'));
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $before = $this->hash();

        $this->change(current: 'not-it')
            ->assertStatus(422)
            ->assertSee('That is not your current password.');

        $this->assertSame($before, $this->hash());
    }

    public function test_a_short_or_mismatched_new_password_is_refused(): void
    {
        $this->change(new: 'short', confirmation: 'short')->assertStatus(422);
        $this->change(confirmation: 'something-else')
            ->assertStatus(422)
            ->assertSee('The passwords do not match.');
    }

    public function test_a_spent_budget_refuses_even_the_right_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->change(current: 'guess-' . $i)->assertStatus(422);
        }

        $this->change()->assertStatus(429);
    }

    public function test_a_typo_in_the_confirmation_does_not_spend_the_budget(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->change(confirmation: 'typo-' . $i)->assertStatus(422);
        }

        $this->change()->assertOk();
    }

    private function change(
        string $current = TestApp::PASSWORD,
        string $new = self::NEW_PASSWORD,
        ?string $confirmation = null,
    ): TestResponse {
        return $this->http->post('/admin/settings/account', [
            'current_password' => $current,
            'password' => $new,
            'password_confirmation' => $confirmation ?? $new,
        ]);
    }

    private function hash(): string
    {
        $row = $this->app->db()->selectOne("SELECT password_hash FROM users WHERE username = 'clerk'");

        return (string) $row['password_hash'];
    }
}
