<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HtmxResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class HtmxResponseTest extends TestCase
{
    private function response(): ResponseInterface
    {
        return (new Psr17Factory)->createResponse();
    }

    public function testSetsSimpleDirectiveHeaders(): void
    {
        $response = (new HtmxResponse)
            ->redirect('/login')
            ->pushUrl('/users')
            ->replaceUrl('/x')
            ->retarget('#list')
            ->reswap('outerHTML')
            ->reselect('#row')
            ->location('/spa')
            ->applyTo($this->response());

        $this->assertSame('/login', $response->getHeaderLine('HX-Redirect'));
        $this->assertSame('/users', $response->getHeaderLine('HX-Push-Url'));
        $this->assertSame('/x', $response->getHeaderLine('HX-Replace-Url'));
        $this->assertSame('#list', $response->getHeaderLine('HX-Retarget'));
        $this->assertSame('outerHTML', $response->getHeaderLine('HX-Reswap'));
        $this->assertSame('#row', $response->getHeaderLine('HX-Reselect'));
        $this->assertSame('/spa', $response->getHeaderLine('HX-Location'));
    }

    public function testRefreshSetsTrue(): void
    {
        $response = (new HtmxResponse)->refresh()->applyTo($this->response());

        $this->assertSame('true', $response->getHeaderLine('HX-Refresh'));
    }

    public function testLeavesResponseUntouchedWhenNothingSet(): void
    {
        $response = (new HtmxResponse)->applyTo($this->response());

        $this->assertFalse($response->hasHeader('HX-Trigger'));
        $this->assertFalse($response->hasHeader('HX-Redirect'));
    }

    public function testSingleEventTriggerUsesPlainName(): void
    {
        $response = (new HtmxResponse)->trigger('cartUpdated')->applyTo($this->response());

        $this->assertSame('cartUpdated', $response->getHeaderLine('HX-Trigger'));
    }

    public function testMultipleDetaillessTriggersAreCommaSeparated(): void
    {
        $response = (new HtmxResponse)
            ->trigger('a')
            ->trigger('b')
            ->applyTo($this->response());

        $this->assertSame('a, b', $response->getHeaderLine('HX-Trigger'));
    }

    public function testTriggerWithDetailEncodesAsJson(): void
    {
        $response = (new HtmxResponse)
            ->trigger('showMessage', ['level' => 'info', 'text' => 'Saved'])
            ->applyTo($this->response());

        $decoded = json_decode($response->getHeaderLine('HX-Trigger'), true);
        $this->assertSame(['showMessage' => ['level' => 'info', 'text' => 'Saved']], $decoded);
    }

    public function testMixedTriggersAllEncodeAsJson(): void
    {
        // Once any event carries a detail, every event must move to the JSON form.
        $response = (new HtmxResponse)
            ->trigger('plain')
            ->trigger('rich', ['n' => 1])
            ->applyTo($this->response());

        $decoded = json_decode($response->getHeaderLine('HX-Trigger'), true);
        $this->assertSame(['plain' => null, 'rich' => ['n' => 1]], $decoded);
    }

    public function testTriggerDetailKeepsSlashesAndUnicodeUnescaped(): void
    {
        // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE keep the header compact
        // and readable; lock that choice in.
        $response = (new HtmxResponse)
            ->trigger('navigate', ['url' => '/users/1', 'label' => 'café'])
            ->applyTo($this->response());

        $header = $response->getHeaderLine('HX-Trigger');

        // Slashes and unicode both stay literal — no \/ and no \uXXXX escaping.
        $this->assertSame('{"navigate":{"url":"/users/1","label":"café"}}', $header);
        // ... and it still round-trips back to the original detail.
        $this->assertSame(
            ['navigate' => ['url' => '/users/1', 'label' => 'café']],
            json_decode($header, true),
        );
    }
}
