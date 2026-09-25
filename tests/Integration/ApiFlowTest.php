<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\UserProviderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A client with a personal token and no browser: no session, no CSRF token,
 * and an answer in JSON rather than a redirect to a login page.
 */
#[CoversNothing]
final class ApiFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    private function token(string $username = 'ada'): string
    {
        $id = $this->app->seed($username);
        $user = $this->app->get(UserProviderInterface::class)->byIdentifier($id) ?? throw new RuntimeException('not seeded');

        return $this->app->get(ApiTokens::class)->issue($user, 'CLI')->plain;
    }

    public function test_a_token_reads_its_owner_with_no_session(): void
    {
        $token = $this->token();

        $response = $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie');

        $this->assertSame('application/json', explode(';', $response->header('Content-Type'))[0]);
        $this->assertSame(
            ['id' => 1, 'username' => 'ada', 'email' => 'ada@example.com'],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_signed_in_browser_reads_itself_too(): void
    {
        $this->app->seed('ada');
        $this->app->login('ada');

        $response = $this->app->http()->get('/api/v1/me', ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('ada', json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR)['username']);
    }

    public function test_no_credentials_on_the_api_is_a_401_not_a_redirect(): void
    {
        $response = $this->app->http()->get('/api/v1/me', ['Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame('application/problem+json', $response->header('Content-Type'));
    }

    public function test_a_bad_token_is_a_401_that_says_why(): void
    {
        $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Authorization' => 'Bearer hyd_' . str_repeat('x', 43), 'Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function test_a_bad_token_on_a_browser_page_is_a_401_too(): void
    {
        $this->app->http()->unprepared()
            ->get('/login', ['Authorization' => 'Bearer nope', 'Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function test_a_token_cannot_be_spent_on_a_session_sign_out(): void
    {
        $token = $this->token();

        $this->app->http()->unprepared()
            ->post('/logout', [], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->assertStatus(500);
    }

    public function test_an_unsafe_bearer_request_needs_no_csrf_token(): void
    {
        $token = $this->token();

        $this->app->http()->unprepared()
            ->post('/api/v1/me', [], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->assertStatus(405);
    }

    public function test_an_unsafe_cookie_request_still_needs_one(): void
    {
        $this->app->seed('ada');
        $this->app->login('ada');

        $this->app->http()->unprepared()
            ->post('/logout', [], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }
}
