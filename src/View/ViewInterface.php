<?php

declare(strict_types=1);

namespace App\View;

/**
 * Renders a named template to a string of HTML.
 *
 * The seam that keeps the choice of template engine an application concern: the
 * shipped implementation is {@see PhpView} (native PHP templates), but a Twig
 * adapter could be bound in its place without touching any controller.
 */
interface ViewInterface
{
    /**
     * Render a template to HTML.
     *
     * When $layout is false, any extends() the template declares is ignored and
     * only its own body is returned — this is how one template serves both a
     * full page (layout on) and an htmx fragment (layout off) off one route.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], bool $layout = true): string;
}
