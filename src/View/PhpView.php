<?php

declare(strict_types=1);

namespace App\View;

use Hydra\Csrf\CsrfGuard;
use RuntimeException;

/**
 * Native PHP template renderer.
 *
 * A template is a plain `.php` file under the base path. The engine itself is
 * stateless: each render spins up a {@see Template} that owns the per-render
 * state (sections, layout chain) and is the `$this` templates see, so nested
 * partials and layouts can never stomp each other's state.
 *
 * The optional {@see CsrfGuard} is handed down to every Template so views can
 * emit the session's CSRF token — `$this->csrf()` for a hidden field, or
 * `$this->csrfToken()` for the layout's meta tag / hx-headers. It is optional so
 * the renderer stays usable (e.g. in isolation tests) without CSRF wired up.
 */
final class PhpView implements ViewInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?CsrfGuard $csrf = null,
    ) {}

    public function render(string $template, array $data = [], bool $layout = true): string
    {
        return (new Template($this, $data, $layout, $this->csrf))->resolve($template);
    }

    /**
     * Resolve a template name to a readable file path.
     *
     * @internal called by {@see Template}
     */
    public function locate(string $template): string
    {
        $path = $this->basePath . '/' . $template . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("View not found: \"{$template}\" (looked in {$path}).");
        }

        return $path;
    }
}
