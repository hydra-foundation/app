<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Htmx;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class HtmxTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function testIsHtmxTrueOnlyWhenHeaderIsTrue(): void
    {
        $this->assertTrue(Htmx::fromRequest($this->request(['HX-Request' => 'true']))->isHtmx());
        $this->assertFalse(Htmx::fromRequest($this->request())->isHtmx());
        // htmx only ever sends the literal "true"; anything else is not htmx.
        $this->assertFalse(Htmx::fromRequest($this->request(['HX-Request' => 'false']))->isHtmx());
    }

    public function testIsBoosted(): void
    {
        $this->assertTrue(Htmx::fromRequest($this->request(['HX-Boosted' => 'true']))->isBoosted());
        $this->assertFalse(Htmx::fromRequest($this->request())->isBoosted());
    }

    public function testReadsTargetTriggerAndPrompt(): void
    {
        $htmx = Htmx::fromRequest($this->request([
            'HX-Target' => 'main',
            'HX-Trigger' => 'save-btn',
            'HX-Trigger-Name' => 'save',
            'HX-Prompt' => 'delete',
            'HX-Current-URL' => 'https://app.test/users',
        ]));

        $this->assertSame('main', $htmx->target());
        $this->assertSame('save-btn', $htmx->trigger());
        $this->assertSame('save', $htmx->triggerName());
        $this->assertSame('delete', $htmx->prompt());
        $this->assertSame('https://app.test/users', $htmx->currentUrl());
    }

    public function testAbsentHeadersReturnNullNotEmptyString(): void
    {
        $htmx = Htmx::fromRequest($this->request());

        // null is "not sent" — distinct from an empty value a client could send.
        $this->assertNull($htmx->target());
        $this->assertNull($htmx->trigger());
        $this->assertNull($htmx->triggerName());
        $this->assertNull($htmx->prompt());
        $this->assertNull($htmx->currentUrl());
    }
}
