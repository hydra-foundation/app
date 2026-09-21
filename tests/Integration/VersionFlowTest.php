<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
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

    private function expected(): string
    {
        $versions = $this->app->get(Versions::class);
        $this->assertInstanceOf(Versions::class, $versions);

        return htmlspecialchars(implode(' · ', array_filter([$versions->application(), 'Hydra ' . $versions->hydra()])));
    }
}
