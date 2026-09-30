<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** The skeleton's smallest proof that live updates work: a box on the home page that refetches itself on sse:demo. */
#[CoversNothing]
final class StreamDemoFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_a_guest_sees_no_demo(): void
    {
        $this->app->http()->get('/')->assertOk()->assertDontSee('data-stream');
    }

    public function test_a_signed_in_home_page_listens_on_demo_and_refetches_its_box(): void
    {
        $this->app->seed('viewer');
        $this->app->login('viewer');

        $this->app->http()->get('/')->assertOk()->assertSeeInOrder([
            'id="stream-demo"',
            'data-stream="demo"',
            'hx-nonce="',
            'hx-get="/stream/demo"',
            'hx-trigger="sse:demo"',
            'hx-swap="outerHTML"',
        ]);
    }

    public function test_the_box_on_its_own_is_a_fragment(): void
    {
        $this->app->seed('viewer');
        $this->app->login('viewer');

        $response = $this->app->http()->get('/stream/demo')->assertOk()->assertSee('id="stream-demo"');

        $this->assertStringNotContainsString('<html', $response->body());
    }

    public function test_the_box_is_for_signed_in_users_only(): void
    {
        $this->app->http()->get('/stream/demo')->assertRedirect('/login');
    }
}
