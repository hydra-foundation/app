<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Settings → API tokens: made here, shown once, used on the API, revoked here. */
#[CoversNothing]
final class ApiTokenSettingsFlowTest extends TestCase
{
    private const URL = '/admin/settings/tokens';

    private TestApp $app;

    private Client $http;

    private int $id;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->id = $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->app->login('clerk')->assertStatus(302);
    }

    private function plain(TestResponse $response): string
    {
        preg_match('/hyd_[A-Za-z0-9_-]{43}/', $response->body(), $match);

        return $match[0] ?? throw new RuntimeException('No token in the response.');
    }

    /** @return list<\Hydra\Auth\ApiToken> */
    private function stored(int $id): array
    {
        $user = $this->app->get(UserProviderInterface::class)->byIdentifier($id) ?? throw new RuntimeException('not seeded');

        return $this->app->get(ApiTokenStoreInterface::class)->forUser($user);
    }

    public function test_the_tab_is_in_the_settings_nav_and_starts_empty(): void
    {
        $this->http->get('/admin/settings')->assertOk()->assertSee('href="/admin/settings/tokens"');
        $this->http->get(self::URL)->assertOk()->assertSee('No tokens yet');
    }

    public function test_a_new_token_is_shown_once_and_works_on_the_api(): void
    {
        $response = $this->http->post(self::URL, ['intent' => 'create', 'label' => 'Deploy script', 'expires' => 'never', 'current_password' => TestApp::PASSWORD])
            ->assertOk()
            ->assertSee('Deploy script');
        $plain = $this->plain($response);

        $this->http->get(self::URL)->assertOk()->assertSee('Deploy script')->assertDontSee($plain);

        $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_the_expiry_is_counted_from_now(): void
    {
        $this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => '30', 'current_password' => TestApp::PASSWORD])->assertOk();

        $token = $this->stored($this->id)[0];

        $this->assertNotNull($token->expiresAt);
        $this->assertEqualsWithDelta(30 * 86400, $token->expiresAt->getTimestamp() - $token->createdAt->getTimestamp(), 1);
    }

    public function test_a_bad_name_or_expiry_makes_nothing(): void
    {
        $this->http->post(self::URL, ['intent' => 'create', 'label' => '', 'expires' => 'never', 'current_password' => TestApp::PASSWORD])
            ->assertStatus(422)
            ->assertSee('Give the token a name');
        $this->http->post(self::URL, ['intent' => 'create', 'label' => str_repeat('x', 101), 'expires' => 'never', 'current_password' => TestApp::PASSWORD])
            ->assertStatus(422);
        $this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => '7', 'current_password' => TestApp::PASSWORD])
            ->assertStatus(422);

        $this->assertSame([], $this->stored($this->id));
    }

    /**
     * Every create checks the password, and that budget is five an hour for
     * every settings form together, so it is the one that binds first.
     */
    public function test_creating_spends_the_settings_password_budget(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->http->post(self::URL, ['intent' => 'create', 'label' => "t{$i}", 'expires' => 'never', 'current_password' => TestApp::PASSWORD])->assertOk();
        }

        $this->http->post(self::URL, ['intent' => 'create', 'label' => 't6', 'expires' => 'never', 'current_password' => TestApp::PASSWORD])->assertStatus(429);
        $this->assertCount(5, $this->stored($this->id));
    }

    public function test_a_missing_or_wrong_password_makes_nothing(): void
    {
        $this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => 'never'])
            ->assertStatus(422)
            ->assertSee('Enter your current password.');
        $this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => 'never', 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertSee('That is not your current password.');

        $this->assertSame([], $this->stored($this->id));
    }

    public function test_revoking_ends_the_token(): void
    {
        $plain = $this->plain($this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => 'never', 'current_password' => TestApp::PASSWORD]));
        $id = $this->stored($this->id)[0]->id;

        $this->http->post(self::URL, ['intent' => 'revoke', 'token' => (string) $id])->assertOk()->assertDontSee('>CI<');

        $this->assertSame([], $this->stored($this->id));
        $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_another_users_token_is_not_found_and_not_listed(): void
    {
        $other = $this->app->seed('other');
        $user = $this->app->get(UserProviderInterface::class)->byIdentifier($other) ?? throw new RuntimeException('not seeded');
        $theirs = $this->app->get(ApiTokens::class)->issue($user, 'Their laptop')->token;

        $this->http->get(self::URL)->assertOk()->assertDontSee('Their laptop');
        $this->http->post(self::URL, ['intent' => 'revoke', 'token' => (string) $theirs->id])->assertStatus(404);

        $this->assertCount(1, $this->stored($other));
    }

    public function test_making_and_revoking_are_audited(): void
    {
        $this->http->post(self::URL, ['intent' => 'create', 'label' => 'CI', 'expires' => 'never', 'current_password' => TestApp::PASSWORD]);
        $this->http->post(self::URL, ['intent' => 'revoke', 'token' => (string) $this->stored($this->id)[0]->id]);

        $messages = array_column(
            $this->app->pdo()->query('SELECT message FROM audit ORDER BY id')->fetchAll(),
            'message',
        );

        $this->assertSame(['account.api_token_created', 'account.api_token_revoked'], $messages);
    }
}
