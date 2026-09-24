<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Http\Maintenance;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Down through the skeleton's own stack: every page refused with the message,
 * /up still answering, and nothing logged as a fault while it lasts.
 */
#[CoversNothing]
final class MaintenanceFlowTest extends TestCase
{
    private TestApp $app;

    private Client $http;

    private Maintenance $maintenance;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->http = $this->app->http();
        $this->maintenance = $this->app->get(Maintenance::class);
    }

    protected function tearDown(): void
    {
        $this->maintenance->up();
    }

    public function test_down_refuses_pages_with_the_message_and_up_still_answers(): void
    {
        $this->maintenance->down('Moving to a bigger server.', 120);

        $page = $this->http->get('/login', ['Accept' => 'text/html'])
            ->assertStatus(503)
            ->assertSee('Moving to a bigger server.');
        $this->assertSame('120', $page->header('Retry-After'));

        $this->http->get('/up')->assertOk();
    }

    public function test_a_maintenance_window_is_not_logged_as_a_fault(): void
    {
        $this->maintenance->down();

        $this->http->get('/login')->assertStatus(503);

        $this->assertSame([], array_values(array_filter(
            $this->app->log()->records(),
            static fn (array $r): bool => $r['level'] === 'error',
        )));
    }

    public function test_up_serves_again(): void
    {
        $this->maintenance->down();
        $this->maintenance->up();

        $this->http->get('/login')->assertOk();
    }
}
