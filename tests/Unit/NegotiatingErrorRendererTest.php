<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\NegotiatingErrorRenderer;
use Hydra\Http\ErrorContext;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NegotiatingErrorRendererTest extends TestCase
{
    private function renderer(): NegotiatingErrorRenderer
    {
        $psr17 = new Psr17Factory;
        $responder = new Responder($psr17, $psr17);

        return new NegotiatingErrorRenderer($responder, new PlainTextErrorRenderer($responder));
    }

    /** @param array<string, string> $headers */
    private function context(\Throwable $error, int $status, array $headers = [], bool $debug = false): ErrorContext
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/x');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return new ErrorContext($error, $request, $status, $debug);
    }

    public function test_htmx_request_gets_an_html_fragment_bound_for_the_error_region(): void
    {
        // htmx sends Accept: text/html too, so the htmx branch must win over the
        // full-page HTML branch — and land out-of-band so the failed element
        // isn't wiped.
        $response = $this->renderer()->render($this->context(
            new HttpException(422, 'invalid'),
            422,
            ['HX-Request' => 'true', 'Accept' => 'text/html'],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();

        // htmx 4 reads no response header; an out-of-band wrapper is how the
        // fragment reaches a region other than the element that asked for it,
        // and htmx drops the wrapper from the fragment once it has.
        $this->assertStringStartsWith('<div hx-swap-oob="innerHTML:#app-error">', $body);
        $this->assertStringContainsString('invalid', $body);
        // A fragment, not a whole document.
        $this->assertStringNotContainsString('<!doctype html>', $body);
    }

    public function test_json_accept_gets_a_json_body(): void
    {
        $response = $this->renderer()->render($this->context(
            new HttpException(404),
            404,
            ['Accept' => 'application/json'],
        ));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['error' => 'Not Found', 'status' => 404],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_html_accept_gets_a_full_page(): void
    {
        $response = $this->renderer()->render($this->context(
            new HttpException(403, 'not yours'),
            403,
            ['Accept' => 'text/html'],
        ));

        $this->assertSame(403, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<!doctype html>', $body);
        $this->assertStringContainsString('not yours', $body);
    }

    public function test_no_recognised_accept_falls_back_to_plain_text(): void
    {
        // curl / health checks: no Accept negotiation, the plain-text default.
        $response = $this->renderer()->render($this->context(new HttpException(500), 500));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function test_production_never_leaks_a_generic_throwable_message(): void
    {
        $response = $this->renderer()->render($this->context(
            new RuntimeException('secret dsn here'),
            500,
            ['Accept' => 'application/json'],
        ));

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('Internal Server Error', $decoded['error']);
        $this->assertArrayNotHasKey('exception', $decoded);
        $this->assertStringNotContainsString('secret dsn', (string) $response->getBody());
    }

    public function test_debug_mode_adds_exception_detail_to_json(): void
    {
        $response = $this->renderer()->render($this->context(
            new RuntimeException('boom'),
            500,
            ['Accept' => 'application/json'],
            debug: true,
        ));

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(RuntimeException::class, $decoded['exception']);
        $this->assertSame('boom', $decoded['message']);
    }

    public function test_html_fragment_escapes_the_message(): void
    {
        // A developer-authored HttpException message still gets HTML-escaped so a
        // reflected value can't break out of the markup.
        $response = $this->renderer()->render($this->context(
            new HttpException(400, '<script>alert(1)</script>'),
            400,
            ['HX-Request' => 'true'],
        ));

        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }
}
