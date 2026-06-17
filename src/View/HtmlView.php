<?php

declare(strict_types=1);

namespace App\View;

use Stringable;

/**
 * Marks a string as already-safe HTML that must NOT be escaped again.
 *
 * {@see PhpView::e()} treats every value as untrusted unless it is an HtmlView
 * instance, so the only way to emit raw markup is to say so explicitly — the
 * default is always to escape.
 */
final class HtmlView implements Stringable
{
    public function __construct(private readonly string|Stringable $html) {}

    public function __toString(): string
    {
        return (string) $this->html;
    }
}
