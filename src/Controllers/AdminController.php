<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Http\Attributes\{Route, RouteGroup};
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Admin controller
 *
 * The admin root
 */
#[RouteGroup('/admin', middleware: [AuthenticateMiddleware::class])]
final class AdminController extends Controller
{
    #[Route('/')]
    public function index(): Response
    {
        return $this->respond->redirect('/admin/dashboard');
    }
}
