<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\View\HtmlView;
use App\View\PhpView;
use App\View\ViewInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpViewTest extends TestCase
{
    private string $dir;
    private PhpView $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-views-' . uniqid('', true);
        mkdir($this->dir);
        $this->view = new PhpView($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        rmdir($this->dir);
    }

    private function writeTemplate(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', $contents);
    }

    public function testIsViewInterface(): void
    {
        $this->assertInstanceOf(ViewInterface::class, $this->view);
    }

    public function testRendersTemplateWithData(): void
    {
        $this->writeTemplate('hello', 'Hello, <?= $this->e($name) ?>!');

        $this->assertSame('Hello, Will!', $this->view->render('hello', ['name' => 'Will']));
    }

    public function testEscapesUntrustedDataByDefault(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testEscapesQuotes(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '"hi" \'there\'']);

        $this->assertStringNotContainsString('"', $out);
        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringContainsString('&#039;', $out);
    }

    public function testHtmlInstancePassesThroughUnescaped(): void
    {
        $this->writeTemplate('x', '<?= $this->e($markup) ?>');

        $out = $this->view->render('x', ['markup' => new HtmlView('<b>bold</b>')]);

        // Explicitly-trusted markup is the ONLY way raw HTML reaches output.
        $this->assertSame('<b>bold</b>', $out);
    }

    public function testNonHtmlStringableIsEscaped(): void
    {
        // A domain value object (Money, Uuid, ...) is untrusted like any string:
        // the safe path escapes it instead of throwing a TypeError.
        $this->writeTemplate('x', '<?= $this->e($value) ?>');

        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>5 & 6</b>';
            }
        };

        $out = $this->view->render('x', ['value' => $value]);

        $this->assertSame('&lt;b&gt;5 &amp; 6&lt;/b&gt;', $out);
    }

    public function testHtmlWrapsAStringableUnescaped(): void
    {
        $this->writeTemplate('x', '<?= $this->e($markup) ?>');

        $inner = new class implements \Stringable {
            public function __toString(): string
            {
                return '<i>raw</i>';
            }
        };

        $out = $this->view->render('x', ['markup' => new HtmlView($inner)]);

        $this->assertSame('<i>raw</i>', $out);
    }

    public function testRendersPartialViaThis(): void
    {
        $this->writeTemplate('partial', 'Hi <?= $this->e($name) ?>');
        $this->writeTemplate('page', 'A: <?= $this->partial("partial", ["name" => $name]) ?>');

        $this->assertSame('A: Hi Will', $this->view->render('page', ['name' => 'Will']));
    }

    public function testPartialIgnoresAStrayExtends(): void
    {
        // A partial renders as a bare fragment: an extends() inside it must not
        // wrap the partial in a layout.
        $this->writeTemplate('wrap', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('partial', '<?php $this->extends("wrap") ?>FRAG');
        $this->writeTemplate('page', '[<?= $this->partial("partial") ?>]');

        $this->assertSame('[FRAG]', $this->view->render('page'));
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render('does-not-exist');
    }

    public function testInternalVariableNamesAreNotClobberedByData(): void
    {
        // Data keyed like the renderer's internals must not break rendering.
        $this->writeTemplate('x', 'ok');

        $this->assertSame('ok', $this->view->render('x', ['__path' => 'evil', '__data' => 'evil']));
    }

    public function testOutputBufferIsCleanedWhenTemplateThrows(): void
    {
        $this->writeTemplate('boom', 'partial<?php throw new \RuntimeException("boom"); ?>');

        $level = ob_get_level();
        try {
            $this->view->render('boom');
            $this->fail('expected the template exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level(), 'no leaked output buffer');
    }
}
