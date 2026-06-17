<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Middleware\SecurityHeadersMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_stamps_the_security_headers_on_the_response(): void
    {
        $response = (new SecurityHeadersMiddleware)->process($this->request(), $this->handler());

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function test_preserves_the_inner_responses_status_and_body(): void
    {
        $response = (new SecurityHeadersMiddleware)->process(
            $this->request(),
            $this->handler(status: 404, body: 'gone'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('gone', (string) $response->getBody());
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/');
    }

    private function handler(int $status = 200, string $body = ''): RequestHandlerInterface
    {
        return new class($status, $body) implements RequestHandlerInterface {
            public function __construct(private int $status, private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                return $factory->createResponse($this->status)
                    ->withBody($factory->createStream($this->body));
            }
        };
    }
}
