<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Responder;
use Hydra\Seo\SiteMeta;
use Hydra\View\Contracts\ViewInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * The public front door: everything reachable without an account, and for a
 * signed-in visitor, a box that refreshes live when `demo` is broadcast.
 */
final class HomeController extends Controller
{
    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly GuardInterface $guard,
        private readonly ClockInterface $clock,
        private readonly SiteMeta $site,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/')]
    public function index(): Response
    {
        return $this->render('home', [
            'demo' => $this->guard->check() ? $this->clock->now() : null,
            // The one public page, so the one that asks to be indexed and
            // previewed; every page without a Meta is noindex (layouts/base).
            'meta' => $this->site->page('Home', 'A small PHP framework created by Will Hleucka.', '/'),
        ]);
    }

    /** The live box on its own, which it fetches to replace itself on sse:demo. */
    #[Route('/stream/demo', middleware: [AuthenticateMiddleware::class])]
    public function demo(): Response
    {
        return $this->render('partials/stream-demo', ['at' => $this->clock->now()], layout: false);
    }
}
