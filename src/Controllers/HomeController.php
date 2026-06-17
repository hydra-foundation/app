<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Http\Attributes\Route;
use Psr\Http\Message\ResponseInterface;

final class HomeController extends Controller
{
    #[Route('/')]
    public function index(): ResponseInterface
    {
        return $this->render('home');
    }

}
