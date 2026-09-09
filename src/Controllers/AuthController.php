<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Http\Input;
use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use Hydra\View\Contracts\ViewInterface;
use App\ViewModels\LoginViewModel;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Htmx;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Authentication controller
 */
final class AuthController extends Controller
{
    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly GuardInterface $guard,
        private readonly Validator $validator,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/login', middleware: [RedirectAuthenticatedMiddleware::class])]
    public function showLogin(): Response
    {
        return $this->render('auth/login/index', ['vm' => new LoginViewModel]);
    }

    #[Route('/login', methods: ['POST'], middleware: [RedirectAuthenticatedMiddleware::class])]
    public function login(Request $request): Response
    {
        $input = Input::fromRequest($request);
        $username = trim($input->string('username'));
        $password = $input->string('password'); // not trimmed, spaces may matter

        // Both fields required before we touch the guard
        $result = $this->validator->validate(
            [
                'username' => $username,
                'password' => $password
            ],
            [
                'username' => [new Required('Enter your username.')],
                'password' => [new Required('Enter your password.')],
            ],
        );

        if ($result->passes() && $this->guard->attempt($username, $password)) {
            return $this->respond->redirect('/admin');
        }

        // A failed login is deliberately vague
        $errors = $result->passes()
            ? ['credentials' => "Those credentials don't match our records."]
            : $result->errors();

        $route = Htmx::fromRequest($request)->isHtmx()
            ? 'auth/login/form'
            : 'auth/login/index';

        return $this->render(
            $route,
            ['vm' => new LoginViewModel($username, $errors)],
            Status::UnprocessableEntity,
        );
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->guard->logout();
        return $this->respond->redirect('/login');
    }
}
