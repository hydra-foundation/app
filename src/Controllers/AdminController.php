<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\View\Contracts\ViewInterface;
use App\ViewModels\AdminViewModel;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Attributes\RouteGroup;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Admin controller
 */
#[RouteGroup('/admin', middleware: [AuthenticateMiddleware::class])]
final class AdminController extends Controller
{
    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly GuardInterface $guard,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/')]
    public function index(): Response
    {
        $current = $this->guard->user();

        return $this->render('admin/index', [
            'vm' => new AdminViewModel($current)
        ]);
    }
}
