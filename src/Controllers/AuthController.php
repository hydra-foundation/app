<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\User;
use Hydra\Http\Htmx;
use Hydra\Http\HtmxResponse;
use Hydra\Http\Input;
use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use Hydra\View\Contracts\ViewInterface;
use App\ViewModels\AccountViewModel;
use App\ViewModels\LoginViewModel;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The auth vertical slice: a login form, a logout action, and one route behind
 * the guard. It only ever talks to {@see GuardInterface} — never the session
 * key, the user provider, or the hasher — so the whole credential dance is the
 * guard's `attempt()` call.
 *
 * Like the other slices it serves htmx and plain browsers from the same routes:
 * a successful login/logout answers an htmx request with an HX-Redirect header
 * and a normal request with a 302.
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
        // An already-signed-in visitor never reaches here: the guest middleware
        // on this route redirects them away before the controller runs.
        return $this->render('auth/login', ['vm' => new LoginViewModel]);
    }

    #[Route('/login', methods: ['POST'], middleware: [RedirectAuthenticatedMiddleware::class])]
    public function login(Request $request): Response
    {
        $input = Input::fromRequest($request);
        $username = trim($input->string('username'));
        $password = $input->string('password'); // not trimmed — spaces may matter

        // Both fields required before we touch the guard: don't run a credential
        // check (or its timing-equalised dummy hash) for an obviously empty form.
        $result = $this->validator->validate(
            ['username' => $username, 'password' => $password],
            [
                'username' => [new Required('Enter your username.')],
                'password' => [new Required('Enter your password.')],
            ],
        );

        if ($result->passes() && $this->guard->attempt($username, $password)) {
            return $this->redirectTo($request, '/dashboard');
        }

        // A failed login is deliberately vague: a present-but-wrong username and a
        // present-but-wrong password give the SAME message, so the form never
        // reveals which accounts exist. The empty-field messages above are safe
        // to show because they reveal nothing about stored data.
        $errors = $result->passes()
            ? ['credentials' => 'Those credentials don\'t match our records.']
            : $result->errors();

        return $this->render(
            'auth/login',
            ['vm' => new LoginViewModel(username: $username, errors: $errors)],
            Status::UnprocessableEntity,
        );
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->guard->logout();

        return $this->redirectTo($request, '/login');
    }

    #[Route('/dashboard', middleware: [AuthenticateMiddleware::class])]
    public function dashboard(): Response
    {
        // The route is guarded, so user() is never null here. The guard's contract
        // returns the interface; in this app that object is always our User, which
        // is the only place the username lives.
        $user = $this->guard->user();
        $username = $user instanceof User ? $user->username : (string) $user?->getAuthIdentifier();

        return $this->render('auth/dashboard', ['vm' => new AccountViewModel($username)]);
    }

    /**
     * Redirect after a state change, in the transport the request speaks: an
     * HX-Redirect header for htmx (which would otherwise swallow a 302's body),
     * a plain 302 for a normal browser navigation.
     */
    private function redirectTo(Request $request, string $to): Response
    {
        if (Htmx::fromRequest($request)->isHtmx()) {
            return (new HtmxResponse)->redirect($to)->applyTo($this->respond->noContent());
        }

        return $this->respond->redirect($to);
    }
}
