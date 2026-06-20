<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\View\Contracts\ViewInterface;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface;

/**
 * Base controller: provides response and view helpers.
 *
 * Subclasses with their own dependencies forward the base args:
 *   public function __construct(Responder $respond, ViewInterface $view, private Repo $repo)
 *   { parent::__construct($respond, $view); }
 */
abstract class Controller
{
    public function __construct(
        protected readonly Responder $respond,
        protected readonly ViewInterface $view,
    ) {}

    /**
     * Render a template to an HTML response — the DX shortcut for the common
     * case, over view->render() followed by respond->html().
     *
     * Pass layout: false to return just the template's body (no surrounding
     * layout) — typically layout: !$htmx->isHtmx() to serve a full page on a
     * normal load and a bare fragment on an htmx swap from the same route.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int|Status $status = Status::Ok, bool $layout = true): ResponseInterface
    {
        return $this->respond->html($this->view->render($template, $data, $layout), $status);
    }

    /**
     * Stop handling and signal an HTTP error condition. The pipeline's
     * ErrorHandlerMiddleware turns this into the response, so a controller can
     * bail out — abort(404), abort(403, 'not yours') — without building one.
     *
     * @return never
     */
    protected function abort(int|Status $status, string $message = ''): never
    {
        throw new HttpException($status instanceof Status ? $status->value : $status, $message);
    }
}
