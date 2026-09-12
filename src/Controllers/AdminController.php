<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Http\Attributes\{Route, RouteGroup};
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Owns /admin itself. The screens below it belong to the admin modules, so all
 * this holds is the group that puts the whole area behind authentication, and a
 * root that sends a visitor on to the dashboard.
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
