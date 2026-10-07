<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\View\Contracts\MarkdownInterface;
use Hydra\View\Contracts\ViewInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Markdown as the app wires it: hydrakit/commonmark registered, handed to the
 * view, and safe by default from any template.
 */
#[CoversNothing]
final class MarkdownWiringTest extends TestCase
{
    private TestApp $app;

    private ?string $template = null;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    protected function tearDown(): void
    {
        if ($this->template !== null) {
            @unlink($this->template);
        }
    }

    public function test_the_container_builds_a_renderer(): void
    {
        $html = (string) $this->app->get(MarkdownInterface::class)->toHtml('**bold**');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_a_view_prints_markdown_with_raw_html_escaped(): void
    {
        $name = 'markdown-wiring-' . bin2hex(random_bytes(4));
        $this->template = dirname(__DIR__, 2) . "/views/{$name}.php";
        file_put_contents($this->template, '<?= $this->markdown("## Hello <script>alert(1)</script>") ?>');

        $out = $this->app->get(ViewInterface::class)->render($name, layout: false);

        $this->assertStringContainsString('<h2 id="hello', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }
}
