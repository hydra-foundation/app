<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Http\CorsConfig;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** A frontend on another origin calling the API, as the skeleton stacks the middleware. */
#[CoversNothing]
final class CorsFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->container()->instance(CorsConfig::class, new CorsConfig(allowedOrigins: ['https://a.test']));
    }

    private function preflight(string $origin, string $path = '/api/v1/me'): TestResponse
    {
        $http = $this->app->http();

        return $http->send(
            $http->request('OPTIONS', $path)
                ->withHeader('Origin', $origin)
                ->withHeader('Access-Control-Request-Method', 'GET')
                ->withHeader('Access-Control-Request-Headers', 'authorization'),
        );
    }

    public function test_a_preflight_from_the_allowed_origin_is_answered(): void
    {
        $this->preflight('https://a.test')
            ->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'https://a.test')
            ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
            ->assertHeader('Vary', 'Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_a_preflight_from_elsewhere_allows_nothing(): void
    {
        $this->preflight('https://b.test')
            ->assertStatus(204)
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_401_is_still_readable_by_the_calling_page(): void
    {
        $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Origin' => 'https://a.test', 'Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertHeader('Access-Control-Allow-Origin', 'https://a.test');
    }

    public function test_a_refused_token_is_readable_too(): void
    {
        $this->app->http()->unprepared()
            ->get('/api/v1/me', ['Origin' => 'https://a.test', 'Authorization' => 'Bearer nope', 'Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertHeader('Access-Control-Allow-Origin', 'https://a.test');
    }

    public function test_the_admin_never_speaks_cors(): void
    {
        $this->app->http()
            ->get('/login', ['Origin' => 'https://a.test'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_the_shipped_environment_leaves_cors_off(): void
    {
        $app = TestApp::boot();

        $response = $app->http()->unprepared()
            ->get('/api/v1/me', ['Origin' => 'https://a.test', 'Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->assertStringNotContainsString('Origin', $response->header('Vary'));
    }
}
