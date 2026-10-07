<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Entities\Role;
use App\Providers\AppServiceProvider;
use App\Tests\Support\TestApp;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\HttpCache;
use Hydra\Http\HttpCacheMiddleware;
use Hydra\Http\Release;
use Hydra\Http\Responder;
use Hydra\Http\Testing\FakeHandler;
use Hydra\Session\StartSessionMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton's caching as a whole: every page says no-store unless its
 * controller says otherwise, with one Cache-Control and none of PHP's old
 * headers, and a page that says public gets its 304s with the deploy mixed
 * into its ETag.
 */
#[CoversNothing]
final class HttpCacheTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_a_page_that_says_nothing_is_never_kept(): void
    {
        $response = $this->app->http()->get('/')->assertOk();

        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame('', $response->header('Pragma'));
        $this->assertSame('', $response->header('Expires'));
    }

    public function test_a_signed_in_page_is_never_kept(): void
    {
        $this->app->seed('boss', Role::Admin);
        $this->app->login('boss')->assertStatus(302);

        $this->assertSame('no-store', $this->app->http()->get('/admin/users')->assertOk()->header('Cache-Control'));
    }

    public function test_it_sits_outside_the_session_and_the_error_handler(): void
    {
        $order = array_flip(AppServiceProvider::MIDDLEWARE);

        $this->assertLessThan($order[StartSessionMiddleware::class], $order[HttpCacheMiddleware::class]);
        $this->assertLessThan($order[ErrorHandlerMiddleware::class], $order[HttpCacheMiddleware::class]);
    }

    public function test_a_public_page_gets_its_304(): void
    {
        $responder = $this->app->get(Responder::class);
        $cache = HttpCache::public(maxAge: 60)->etag('post', 1);
        $page = $responder->cached($responder->html('<p>post</p>'), $cache);

        $again = $this->app->get(HttpCacheMiddleware::class)->process(
            new ServerRequest('GET', '/posts/hello', ['If-None-Match' => $page->getHeaderLine('ETag')]),
            FakeHandler::respondingWith($page),
        );

        $this->assertSame(304, $again->getStatusCode());
        $this->assertTrue($responder->isFresh(new ServerRequest('GET', '/', ['If-None-Match' => $page->getHeaderLine('ETag')]), $cache));
    }

    public function test_a_public_page_handing_out_a_cookie_is_kept_only_by_its_reader(): void
    {
        $responder = $this->app->get(Responder::class);
        $page = $responder->cached($responder->html(''), HttpCache::public(maxAge: 60))->withHeader('Set-Cookie', 'hydra_session=new');

        $response = $this->app->get(HttpCacheMiddleware::class)->process(new ServerRequest('GET', '/'), FakeHandler::respondingWith($page));

        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
    }

    public function test_the_release_is_named_by_the_env_or_the_commit(): void
    {
        $this->config(release: 'v-test', debug: false);
        $named = $this->app->get(Release::class);

        $this->assertSame('v-test', $named->id);
        $this->assertTrue($named->conditional);

        $this->app = TestApp::boot();
        $this->config(release: '', debug: false);

        $this->assertMatchesRegularExpression('/^([0-9a-f]{40}|[0-9a-f]{64}|unknown)$/', $this->app->get(Release::class)->id);
    }

    public function test_in_development_there_are_no_304s(): void
    {
        $this->config(release: 'v-test', debug: true);

        $this->assertFalse($this->app->get(Release::class)->conditional);
    }

    private function config(string $release, bool $debug): void
    {
        $base = $this->app->get(AppConfig::class);

        $this->app->container()->instance(AppConfig::class, new AppConfig(
            name: $base->name,
            url: $base->url,
            debug: $debug,
            timezone: $base->timezone,
            key: $base->key,
            forceHttps: $base->forceHttps,
            trustForwardedProto: $base->trustForwardedProto,
            trustedProxies: $base->trustedProxies,
            release: $release,
        ));
    }
}
