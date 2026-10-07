<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Filesystem\FilesystemConfig;
use Hydra\View\Contracts\ImagesInterface;
use Hydra\View\Contracts\ViewInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton's pictures: a view's $this->image() reaches hydrakit/image
 * with the app's presets, and the copies land on the public disk that
 * filesystem configured, under the URL it serves them at.
 */
#[CoversNothing]
final class ImagesWiringTest extends TestCase
{
    private string $template;

    private string $picture;

    protected function tearDown(): void
    {
        foreach ([$this->template ?? '', $this->picture ?? ''] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_a_view_prints_a_picture_at_a_presets_sizes(): void
    {
        $app = TestApp::boot();
        $disk = $app->get(FilesystemConfig::class);
        $root = dirname($disk->publicLink);
        @mkdir($root . '/images', 0o777, true);
        $this->picture = $root . '/images/wiring-' . bin2hex(random_bytes(4)) . '.jpg';
        $image = imagecreatetruecolor(2000, 1000);
        $this->assertNotFalse($image);
        imagejpeg($image, $this->picture);

        $source = '/images/' . basename($this->picture);
        $this->template = dirname(__DIR__, 2) . '/views/wiring-image-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->template, '<?= $this->image("' . $source . '", "content", alt: "Wiring") ?>');

        $html = $app->get(ViewInterface::class)->render(basename($this->template, '.php'), layout: false);
        $variants = $app->get(ImagesInterface::class)->variants($source, 'content');

        $this->assertCount(3, $variants);
        $this->assertStringStartsWith('<img src="' . $variants[1]->url . '"', $html);
        $this->assertStringContainsString('960w', $html);
        $this->assertStringStartsWith('/storage/variants/content/', $variants[0]->url);
        $this->assertFileExists($disk->publicRoot . '/' . substr($variants[0]->url, strlen('/storage/')));
    }
}
