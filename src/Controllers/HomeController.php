<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Http\Attributes\Route;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * The public front door: everything reachable without an account.
 */
final class HomeController extends Controller
{
    #[Route('/')]
    public function index(): Response
    {
        return $this->render('home');
    }
}
