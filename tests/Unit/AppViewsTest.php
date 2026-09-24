<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The nonce discipline, held over this application's own templates.
 *
 * The admin package holds the same contract over the templates it ships
 * ({@see \Hydra\Admin\Tests\Unit\ShippedViewsTest}), but that test can only see
 * its own views directory. Everything under views/ here was outside any such
 * check, which is the worst place for it to be: under the policy a forgotten
 * nonce does not raise, it just quietly stops working.
 */
#[CoversNothing]
final class AppViewsTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';

    /**
     * hx-csp strips the htmx attributes off any element whose hx-nonce does not
     * match the page's, so a template that forgets one renders a control that
     * silently does nothing.
     *
     * hx-swap-oob is the exception, and only because htmx reads it off the
     * parsed fragment and removes it before the element is ever initialised;
     * the gate runs at initialisation, so it never sees the attribute.
     */
    public function test_every_htmx_element_carries_the_nonce(): void
    {
        $elements = $this->openingTags();

        $this->assertNotEmpty($elements, 'the element scan found nothing it should have');

        $htmx = array_filter(
            $elements,
            fn (array $e): bool => preg_match('~\bhx-(?!swap-oob)[a-z]~', $this->names($e[2])) === 1,
        );

        $this->assertNotEmpty($htmx, 'no htmx elements found; this test is no longer testing anything');

        foreach ($htmx as [$file, $tag, $attributes]) {
            $this->assertStringContainsString(
                'hx-nonce',
                $this->names($attributes),
                "<{$tag}> in {$file} takes htmx attributes without an hx-nonce",
            );
        }
    }

    /**
     * The same failure one layer down, for inline script only. A file script is
     * covered by script-src's 'self' whether or not it carries a nonce, but
     * there is no 'unsafe-inline' in the policy and there must never be, so an
     * inline block that forgets the nonce simply does not run.
     *
     * Nothing under views/ has an inline script today. This is here so that the
     * first one to arrive has to carry a nonce to get in.
     */
    public function test_every_inline_script_carries_the_nonce(): void
    {
        $scripts = array_filter(
            $this->openingTags(),
            static fn (array $e): bool => $e[1] === 'script',
        );

        // Also proves the scan still reaches script tags at all, which is the
        // only way this test can fail to notice an unnonced one.
        $this->assertNotEmpty($scripts, 'the scan found no script tags');

        foreach ($scripts as [$file, , $attributes]) {
            $names = $this->names($attributes);

            if (str_contains($names, 'src=')) {
                continue;
            }

            $this->assertStringContainsString(
                'nonce=',
                $names,
                "the inline <script> in {$file} is not nonced, so the policy will not run it",
            );
        }
    }

    /**
     * The attribute string with every value blanked, leaving the names. What
     * an attribute is called and what it happens to contain are different
     * questions, and only the first one is being asked: a
     * `content='extensions:"hx-csp"'` meta tag is configuration for htmx, not
     * an element htmx will ever initialise.
     */
    /**
     * A control named after a form property replaces it: in a form holding
     * name="form", form.form is that input. htmx 4 finds a POST's form with
     * elt.form, so it handed the input to new FormData() and the submit threw.
     */
    public function test_no_field_is_named_after_a_form_property(): void
    {
        $shadowing = ['form', 'action', 'method', 'target', 'elements', 'length', 'name', 'id', 'submit', 'reset', 'enctype', 'encoding', 'acceptCharset', 'autocomplete', 'noValidate', 'checkValidity', 'reportValidity', 'requestSubmit', 'nodeName', 'attributes', 'children', 'style'];
        $found = [];

        foreach ($this->openingTags() as [$file, $tag, $attributes]) {
            if (in_array($tag, ['input', 'select', 'textarea', 'button'], true)
                && preg_match('~\bname="([^"]*)"~', $attributes, $m) === 1
                && in_array($m[1], $shadowing, true)) {
                $found[] = "{$file}: <{$tag} name=\"{$m[1]}\">";
            }
        }

        $this->assertSame([], $found);
    }

    private function names(string $attributes): string
    {
        return (string) preg_replace('~=\s*("[^"]*"|\'[^\']*\'|[^\s"\'<>`]+)~', '=', $attributes);
    }

    /**
     * Every opening tag under views/, as [file, tag, attributes]. PHP blocks are
     * blanked before the scan so a `<?=` inside a tag does not read as the start
     * of another one, then the attributes are taken from the ORIGINAL source at
     * the same offset, so what each assertion sees is what the file says.
     *
     * @return list<array{string, string, string}>
     */
    private function openingTags(): array
    {
        $found = [];

        foreach ($this->templates() as $file) {
            $source = (string) file_get_contents($file);
            $masked = (string) preg_replace_callback(
                '~<\?(?:php|=).*?\?>~s',
                static fn (array $m): string => str_repeat(' ', strlen($m[0])),
                $source,
            );

            preg_match_all(
                '~<([a-zA-Z][a-zA-Z0-9]*)((?:[^<>\'"]|"[^"]*"|\'[^\']*\')*?)>~s',
                $masked,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[2] as $index => [, $offset]) {
                $found[] = [
                    $this->relative($file),
                    $matches[1][$index][0],
                    substr($source, $offset, strlen($matches[2][$index][0])),
                ];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS));

        /** @var SplFileInfo $file */
        foreach ($tree as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        $root = (string) realpath(self::VIEWS . '/..');

        return str_replace($root . '/', '', $file);
    }
}
