<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Middleware\RequestLoggingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

final class RequestLoggingMiddlewareTest extends TestCase
{
    public function test_logs_one_line_with_request_and_response_facts(): void
    {
        $logger = new RecordingLogger;
        $request = (new Psr17Factory)->createServerRequest('POST', 'http://x.test/login');

        (new RequestLoggingMiddleware($logger))->process($request, $this->handler(201));

        $this->assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        $this->assertSame('info', $level);
        $this->assertSame('request handled', $message);
        $this->assertSame('POST', $context['method']);
        $this->assertSame('/login', $context['path']);
        $this->assertSame(201, $context['status']);
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertIsFloat($context['duration_ms']);
    }

    public function test_returns_the_inner_response_unchanged(): void
    {
        $response = (new RequestLoggingMiddleware(new RecordingLogger))
            ->process((new Psr17Factory)->createServerRequest('GET', '/'), $this->handler(200));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function handler(int $status): RequestHandlerInterface
    {
        return new class($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory)->createResponse($this->status);
            }
        };
    }
}

/** A PSR-3 logger that records every call for assertion. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
