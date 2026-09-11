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
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Authentication controller
 */
final class AuthController extends Controller
{
    /** Matches the users.username column; anything longer cannot name a row. */
    private const MAX_USERNAME = 255;

    /**
     * bcrypt reads at most 72 bytes and silently ignores the rest, so a longer
     * password is not a stronger one — the cap only refuses input that could
     * never change the outcome. Set above 72 so a pass phrase that trips it is
     * clearly the caller's mistake rather than a limit they had to discover.
     */
    private const MAX_PASSWORD = 4096;

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

        // Bounded, then required, before we touch the guard. The ceilings are
        // far above any real credential and exist to cap what an unauthenticated
        // caller can make the server carry: verifying a password is the most
        // expensive thing this route does, and every byte above these limits is
        // spent parsing, logging and hashing input that could never match.
        $result = $this->validator->validate(
            [
                'username' => $username,
                'password' => $password
            ],
            [
                'username' => [
                    new Required('Enter your username.'),
                    new MaxLength(self::MAX_USERNAME, 'Enter your username.'),
                ],
                'password' => [
                    new Required('Enter your password.'),
                    new MaxLength(self::MAX_PASSWORD, 'Enter your password.'),
                ],
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
