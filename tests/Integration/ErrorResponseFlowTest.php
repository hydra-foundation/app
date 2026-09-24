<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The renderer as the skeleton binds it: the formats are the framework's, and
 * what is the skeleton's is that the htmx region it names is in its layout.
 */
#[CoversNothing]
final class ErrorResponseFlowTest extends TestCase
{
    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->http = $this->app->http();
    }

    public function test_the_htmx_error_region_is_in_the_layout(): void
    {
        $this->http->get('/login')->assertOk()->assertSee('<div id="app-error" role="alert"></div>');

        $this->http->htmx()->get('/no-such-page')
            ->assertStatus(404)
            ->assertSee('hx-swap-oob="innerHTML:#app-error"');
    }

    public function test_an_api_client_gets_problem_details(): void
    {
        $response = $this->http->get('/no-such-page', ['Accept' => 'application/json'])->assertStatus(404);

        $this->assertSame('application/problem+json', $response->header('Content-Type'));
        $this->assertSame(
            ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_browser_gets_a_page(): void
    {
        $this->http->get('/no-such-page', ['Accept' => 'text/html'])
            ->assertStatus(404)
            ->assertSee('<title>404 Not Found</title>');
    }
}
