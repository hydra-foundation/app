<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Admin\Testing\FakeReleaseFeed;
use Hydra\Admin\Updates\UpdateCheck;
use Hydra\Cache\ArrayStore;
use Hydra\Core\Versions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class VersionFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_the_sign_in_page_names_the_versions(): void
    {
        $body = $this->app->http()->get('/login')->assertOk()->body();

        $this->assertStringContainsString('<p class="auth-version">' . $this->expected() . '</p>', $body);
    }

    public function test_the_backend_footer_names_the_versions(): void
    {
        $this->app->seed('boss', Role::Admin);
        $this->app->login('boss')->assertStatus(302);
        $body = $this->app->http()->get('/admin/users')->assertOk()->body();

        $this->assertStringContainsString('<footer class="admin-footer">' . $this->expected() . '</footer>', $body);
    }

    public function test_the_backend_footer_links_a_newer_release(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.8'], security: ['0.9.8']);
        $this->app->container()->instance(UpdateCheck::class, new UpdateCheck($feed, new ArrayStore, '0.9.7'));
        $this->app->seed('boss', Role::Admin);
        $this->app->login('boss')->assertStatus(302);
        $body = $this->app->http()->get('/admin/users')->assertOk()->body();

        $this->assertStringContainsString(
            $this->expected() . ' · <a href="https://hydra.example/docs/changelog.html#v0-9-8" rel="noopener" target="_blank">0.9.8 available (security fix)</a></footer>',
            $body,
        );
    }

    public function test_the_sign_in_page_says_nothing_of_updates(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.8']);
        $this->app->container()->instance(UpdateCheck::class, new UpdateCheck($feed, new ArrayStore, '0.9.7'));

        $this->assertStringNotContainsString('available', $this->app->http()->get('/login')->assertOk()->body());
        $this->assertSame(0, $feed->fetches);
    }

    private function expected(): string
    {
        $versions = $this->app->get(Versions::class);
        $this->assertInstanceOf(Versions::class, $versions);

        return htmlspecialchars(implode(' · ', array_filter([$versions->application(), 'Hydra ' . $versions->hydra()])));
    }
}
