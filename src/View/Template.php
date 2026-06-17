<?php

declare(strict_types=1);

namespace App\View;

use Hydra\Csrf\CsrfGuard;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * One render in progress: the `$this` a template file sees.
 *
 * Holds the state a single inheritance chain needs — captured sections and the
 * pending layout — so it is isolated per render(). Templates call its methods:
 * extends() to wrap in a layout, start()/stop() to capture a named section,
 * section() to yield one, e() to escape, and partial() to nest another view.
 *
 * Inheritance is resolved by output buffering, not compilation: the child runs
 * first into a buffer, extends() merely records the parent, and the child's
 * leftover output becomes the implicit "content" section. The layout is then
 * rendered with those sections available — the inverse of how it reads, which
 * is exactly what a compiling engine like Twig does under the hood.
 */
final class Template
{
    /** @var array<string, string> Captured sections, shared up the layout chain. */
    private array $sections = [];

    /** @var list<string> Names of sections currently being captured (a stack). */
    private array $capturing = [];

    /** The parent layout set by extends(), consumed once then cleared. */
    private ?string $layout = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly PhpView $engine,
        private array $data,
        private readonly bool $wrapLayout = true,
        private readonly ?CsrfGuard $csrf = null,
    ) {}

    public function resolve(string $template): string
    {
        $content = $this->evaluate($template);

        // A fragment render ignores the template's layout and returns its body —
        // the htmx case. extends() still ran; we just don't walk the chain.
        if (!$this->wrapLayout) {
            return $content;
        }

        // Walk up the layout chain: each level's output is the next's content.
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            $this->sections['content'] = $content;
            $content = $this->evaluate($layout);
        }

        return $content;
    }

    /**
     * Wrap this template in a layout. Records the parent (rendered after this
     * template finishes); extra data is merged in for the layout to read.
     *
     * @param array<string, mixed> $data
     */
    public function extends(string $template, array $data = []): void
    {
        $this->layout = $template;

        if ($data !== []) {
            $this->data = array_merge($this->data, $data);
        }
    }

    /** Begin capturing output into a named section. */
    public function start(string $name): void
    {
        $this->capturing[] = $name;
        ob_start();
    }

    /** Finish the most recently started section. */
    public function stop(): void
    {
        if ($this->capturing === []) {
            throw new RuntimeException('stop() called without a matching start().');
        }

        $name = array_pop($this->capturing);
        $this->sections[$name] = (string) ob_get_clean();
    }

    /** Output a captured section, or $default if the child never defined it. */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Escape a value for safe HTML output. HtmlView instances are already trusted
     * and pass through untouched; everything else — including any other
     * Stringable value object — is treated as untrusted and escaped, so
     * forgetting to mark a value can only ever over-escape, never under-escape.
     */
    public function e(string|int|float|bool|Stringable|null $value): string
    {
        if ($value instanceof HtmlView) {
            return (string) $value;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render another template inline and return its HTML. It resolves as its
     * own independent chain, so a partial's sections never touch this render's.
     *
     * Two deliberate contracts: a partial inherits NONE of the parent's data —
     * pass everything it needs explicitly via $data — and it renders as a bare
     * fragment, so a stray extends() inside a partial is ignored rather than
     * wrapping the partial in a full layout.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->engine->render($template, $data, layout: false);
    }

    /**
     * The session's CSRF token (raw), for the layout's meta tag and the
     * hx-headers attribute that makes every htmx request carry it automatically.
     * Minted on first call, then stable — see {@see CsrfGuard::token()}.
     */
    public function csrfToken(): string
    {
        if ($this->csrf === null) {
            throw new RuntimeException('CSRF is not configured for this view (no CsrfGuard was provided).');
        }

        return $this->csrf->token();
    }

    /**
     * A hidden form field carrying the CSRF token, for a plain (non-htmx) form
     * post. Drop `<?= $this->csrf() ?>` inside any <form>; htmx forms get the
     * token from the layout's auto-header and don't strictly need it, but it is
     * harmless to include. The token is hex, so escaping it is belt-and-braces.
     */
    public function csrf(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            CsrfGuard::FIELD,
            $this->e($this->csrfToken()),
        );
    }

    /**
     * Include the template in a $this-bound scope and capture its output. The
     * closure's params are underscore-prefixed and extract() uses EXTR_SKIP so
     * view data can never overwrite the path/data the include relies on.
     */
    private function evaluate(string $template): string
    {
        $path = $this->engine->locate($template);

        $run = function (string $__path, array $__data): void {
            extract($__data, EXTR_SKIP);
            include $__path;
        };

        $level = ob_get_level();
        $openSections = count($this->capturing);
        ob_start();

        try {
            $run->call($this, $path, $this->data);
        } catch (Throwable $e) {
            // Drop this evaluate's buffer plus any sections it left open.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->capturing = [];
            throw $e;
        }

        // A start() with no matching stop() leaves its buffer open and would
        // otherwise strand the template's real output in a dangling buffer.
        // Fail loudly — the mirror of stop()-without-start().
        if (count($this->capturing) !== $openSections) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->capturing = [];
            throw new RuntimeException('start() called without a matching stop().');
        }

        return (string) ob_get_clean();
    }
}
