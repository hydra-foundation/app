<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds the contract between a theme and the stylesheets that consume it in both
 * directions: no consuming sheet may name a colour of its own, and no sheet may
 * ask for a token no theme defines. Either mistake renders anyway, as an
 * unthemed literal or as an empty value the browser silently decides for itself,
 * which is precisely why it is worth a test.
 */
final class ThemeContractTest extends TestCase
{
    private const CSS = __DIR__ . '/../../public/css';

    /** Whatever palettes are on disk: adding one must not mean editing a test. */
    private static function themeNames(): array
    {
        return array_map(
            static fn (string $file): string => basename($file, '.css'),
            glob(self::CSS . '/themes/*.css') ?: [],
        );
    }

    /** Sheets that consume tokens rather than define them. */
    private const CONSUMERS = ['base.css', 'admin.css', 'app.css'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        foreach (self::themeNames() as $theme) {
            yield $theme => [$theme];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function consumers(): iterable
    {
        foreach (self::CONSUMERS as $sheet) {
            yield $sheet => [$sheet];
        }
    }

    #[DataProvider('themes')]
    public function test_a_theme_defines_every_token_the_stylesheets_ask_for(string $theme): void
    {
        $defined = $this->defined($this->read("themes/{$theme}.css"));

        // base.css declares the proportions (fonts, scale, radii), which are
        // the design's and not a theme's, so they count as supplied too.
        $defined = [...$defined, ...$this->defined($this->read('base.css'))];

        $missing = array_values(array_diff($this->used(), $defined));

        $this->assertSame([], $missing, "Theme \"{$theme}\" is missing: " . implode(', ', $missing));
    }

    #[DataProvider('consumers')]
    public function test_a_stylesheet_that_consumes_a_theme_names_no_colour_of_its_own(string $sheet): void
    {
        preg_match_all('~#[0-9a-fA-F]{3,8}\b|\brgba?\([^)]*\)~', $this->read($sheet), $matches);

        $this->assertSame([], $matches[0], "{$sheet} hardcodes a colour a theme cannot reach.");
    }

    public function test_the_default_theme_answers_a_document_that_names_none(): void
    {
        // The layout defaults the attribute, but a page that does not use it,
        // or a fragment rendered without a layout, still has to be readable.
        // :not() keeps the fallback from outranking a named palette.
        $this->assertStringContainsString(':root:not([data-theme])', $this->read('themes/paper.css'));
    }

    #[DataProvider('themes')]
    public function test_every_theme_is_reachable_by_name(string $theme): void
    {
        $this->assertStringContainsString(
            "[data-theme=\"{$theme}\"]",
            $this->read("themes/{$theme}.css"),
            "Theme \"{$theme}\" cannot be selected by name.",
        );
    }

    /**
     * Tokens a sheet asks for. Bootstrap's own are excluded: it defines them.
     *
     * @return list<string>
     */
    private function used(): array
    {
        $used = [];

        foreach (self::CONSUMERS as $sheet) {
            preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $this->read($sheet), $matches);
            $used = [...$used, ...$matches[1]];
        }

        return array_values(array_unique(array_filter(
            $used,
            static fn (string $token): bool => !str_starts_with($token, '--bs-'),
        )));
    }

    /**
     * @return list<string>
     */
    private function defined(string $css): array
    {
        preg_match_all('~^\s*(--[a-z0-9-]+)\s*:~im', $css, $matches);

        return $matches[1];
    }

    private function read(string $path): string
    {
        $file = self::CSS . '/' . $path;

        return file_get_contents($file) ?: self::fail("Missing stylesheet: {$path}.");
    }
}
