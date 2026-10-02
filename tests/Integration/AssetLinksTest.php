<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every stylesheet and script the layout links is fingerprinted and names a
 * file that exists. nginx serves a hashed name as immutable for a year, so a
 * link that skipped asset() would be the one file an edit could not reach;
 * and asset() throws on a typo, which this turns into a failing test rather
 * than a 500 on the first page someone opens.
 */
#[CoversNothing]
final class AssetLinksTest extends TestCase
{
    private const PUBLIC = __DIR__ . '/../../public';

    public function test_every_local_stylesheet_and_script_is_fingerprinted(): void
    {
        $body = TestApp::boot()->http()->get('/login')->assertOk()->body();

        preg_match_all('#(?:href|src)="(/(?:css|js)/[^"]*)"#', $body, $matches);
        $urls = $matches[1];

        $this->assertNotEmpty($urls, 'the layout links no /css or /js files at all');

        foreach ($urls as $url) {
            $this->assertMatchesRegularExpression(
                '#^(/.+)\.[0-9a-f]{10}(\.(?:css|js))$#',
                $url,
                "$url is not fingerprinted: link it through \$this->asset()",
            );

            $file = preg_replace('#\.[0-9a-f]{10}(\.(?:css|js))$#', '$1', $url);
            $this->assertFileExists(self::PUBLIC . $file, "$url names no file");
        }
    }

    public function test_no_view_links_a_stylesheet_or_script_by_its_plain_name(): void
    {
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../views'));
        $plain = [];

        foreach ($views as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('#(?:href|src)="/(?:css|js)/#', (string) file_get_contents($file->getPathname())) === 1) {
                $plain[] = $file->getFilename();
            }
        }

        $this->assertSame([], $plain, 'link these through $this->asset()');
    }
}
