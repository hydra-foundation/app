<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\View\PhpView;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewInheritanceTest extends TestCase
{
    private string $dir;
    private PhpView $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-inherit-' . uniqid('', true);
        mkdir($this->dir);
        $this->view = new PhpView($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeTemplate(string $name, string $contents): void
    {
        $path = $this->dir . '/' . $name . '.php';
        $subdir = dirname($path);
        if (!is_dir($subdir)) {
            mkdir($subdir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function testChildExtendsLayoutAndBodyBecomesContent(): void
    {
        $this->writeTemplate('layouts/base', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?><h1><?= $this->e($name) ?></h1>');

        $out = $this->view->render('home', ['name' => 'Hydra']);

        $this->assertSame('<body><h1>Hydra</h1></body>', $out);
    }

    public function testNamedSectionIsCapturedAndYielded(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title") ?></title><?= $this->section("content") ?>');
        $this->writeTemplate('home', implode('', [
            '<?php $this->extends("layouts/base") ?>',
            '<?php $this->start("title") ?>Home<?php $this->stop() ?>',
            'BODY',
        ]));

        $this->assertSame('<title>Home</title>BODY', $this->view->render('home'));
    }

    public function testSectionDefaultUsedWhenChildOmitsIt(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title", "Hydra") ?></title>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>x');

        $this->assertSame('<title>Hydra</title>', $this->view->render('home'));
    }

    public function testNamedSectionContentIsExcludedFromImplicitContent(): void
    {
        $this->writeTemplate('layouts/base', '[<?= $this->section("content") ?>]');
        $this->writeTemplate('home', implode('', [
            '<?php $this->extends("layouts/base") ?>',
            'A',
            '<?php $this->start("aside") ?>SIDEBAR<?php $this->stop() ?>',
            'B',
        ]));

        // The captured section must not leak into the implicit content body.
        $this->assertSame('[AB]', $this->view->render('home'));
    }

    public function testMultiLevelExtends(): void
    {
        $this->writeTemplate('layouts/skeleton', 'S(<?= $this->section("content") ?>)');
        $this->writeTemplate('layouts/base', '<?php $this->extends("layouts/skeleton") ?>B(<?= $this->section("content") ?>)');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>H');

        // home -> base -> skeleton; each level's body becomes the next's content.
        $this->assertSame('S(B(H))', $this->view->render('home'));
    }

    public function testSectionsDoNotLeakBetweenRenders(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title", "default") ?></title>');
        $this->writeTemplate('with', '<?php $this->extends("layouts/base") ?><?php $this->start("title") ?>Set<?php $this->stop() ?>');
        $this->writeTemplate('without', '<?php $this->extends("layouts/base") ?>x');

        $this->assertSame('<title>Set</title>', $this->view->render('with'));
        // A fresh render must not see the previous render's "title" section.
        $this->assertSame('<title>default</title>', $this->view->render('without'));
    }

    public function testEscapingStillAppliesInsideLayouts(): void
    {
        $this->writeTemplate('layouts/base', '<h1><?= $this->e($name) ?></h1><?= $this->section("content") ?>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>body');

        $out = $this->view->render('home', ['name' => '<script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testLayoutFalseReturnsBareBodyIgnoringExtends(): void
    {
        $this->writeTemplate('layouts/base', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?><h1><?= $this->e($name) ?></h1>');

        // Same template, layout suppressed: the htmx-fragment path.
        $out = $this->view->render('home', ['name' => 'Hydra'], layout: false);

        $this->assertSame('<h1>Hydra</h1>', $out);
    }

    public function testStopWithoutStartThrows(): void
    {
        $this->writeTemplate('bad', '<?php $this->stop() ?>');

        $this->expectException(RuntimeException::class);
        $this->view->render('bad');
    }

    public function testStartWithoutStopThrows(): void
    {
        // An unclosed section would otherwise strand the body in a dangling
        // buffer; it must fail loudly, the mirror of stop()-without-start().
        $this->writeTemplate('bad', 'before<?php $this->start("x") ?>after');

        $this->expectException(RuntimeException::class);
        $this->view->render('bad');
    }

    public function testStartWithoutStopLeaksNoOutputBuffer(): void
    {
        $this->writeTemplate('bad', 'before<?php $this->start("x") ?>after');

        $level = ob_get_level();
        try {
            $this->view->render('bad');
            $this->fail('expected an unclosed start() to throw');
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame($level, ob_get_level(), 'no leaked output buffer');
    }
}
