<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Providers\AppServiceProvider;
use App\Tests\Support\TestApp;
use Hydra\Seo\SiteMeta;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The head of each page as a crawler or a link preview reads it: the public
 * page carries its own Meta, and everything else (sign-in, the admin) keeps
 * its title and asks not to be indexed.
 */
#[CoversNothing]
final class MetaTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_the_home_page_has_its_title_canonical_url_and_preview_tags(): void
    {
        $body = $this->app->http()->get('/')->assertOk()->body();

        $this->assertStringContainsString('<title>Home · Hydra</title>', $body);
        $this->assertStringContainsString('<link rel="canonical" href="' . TestApp::URL . '/">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="' . TestApp::URL . '/">', $body);
        $this->assertStringContainsString('<meta property="og:image" content="' . TestApp::URL . '/icons/android-chrome-512x512.png">', $body);
        $this->assertStringNotContainsString('noindex', $body);
        $this->assertSame(1, substr_count($body, '<title>'));
    }

    public function test_sign_in_keeps_its_title_and_is_not_indexed(): void
    {
        $body = $this->app->http()->get('/login')->assertOk()->body();

        $this->assertStringContainsString('<title>Sign in · Hydra</title>', $body);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringNotContainsString('og:title', $body);
    }

    public function test_the_admin_is_not_indexed(): void
    {
        $this->app->seed('ada', Role::Admin);
        $this->app->login('ada');

        $body = $this->app->http()->get('/admin/dashboard')->assertOk()->body();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
    }

    public function test_robots_txt_keeps_crawlers_out_of_the_admin(): void
    {
        $robots = (string) file_get_contents(__DIR__ . '/../../public/robots.txt');

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        $this->assertMatchesRegularExpression('/^Disallow: \/admin$/m', $robots);
    }

    public function test_the_site_meta_is_built_on_the_app_url_and_name(): void
    {
        $site = AppServiceProvider::siteMeta('https://example.test', 'Example');

        $this->assertSame('https://example.test', $site->baseUrl);
        $this->assertSame('Example', $site->siteName);
        $this->assertSame('About · Example', $site->title('About'));
    }

    public function test_an_app_url_with_a_trailing_slash_names_the_setting_to_fix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL must be the site\'s scheme and host, like https://example.com, with no path or trailing slash; got "https://example.test/".');

        AppServiceProvider::siteMeta('https://example.test/', 'Example');
    }

    public function test_the_container_hands_out_one_site_meta(): void
    {
        $this->assertSame($this->app->get(SiteMeta::class), $this->app->get(SiteMeta::class));
    }
}
