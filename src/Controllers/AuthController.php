<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Http\ParsedBody;
use App\Http\Middleware\LoginThrottleMiddleware;
use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use Hydra\View\Contracts\ViewInterface;
use App\ViewModels\LoginViewModel;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\TwoFactorChallenge;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Htmx;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\Required;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
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
     * password is not a stronger one, and the cap only refuses input that could
     * never change the outcome. Set above 72 so a pass phrase that trips it is
     * clearly the caller's mistake rather than a limit they had to discover.
     */
    private const MAX_PASSWORD = 4096;

    /**
     * Guesses at one username, from anywhere. LoginThrottleMiddleware counts
     * per client, which a guessing run spread over many addresses never
     * spends; this is the budget it cannot spread. Keyed on the name as typed,
     * so an unknown name is counted the same as a real one and the refusal
     * says nothing about which it was.
     *
     * Anyone who knows a username can spend it, which locks that account's
     * password form for the window. That is the trade: the window is short,
     * and the emailed reset is not behind it.
     */
    private const PER_ACCOUNT = 10;
    private const PER_ACCOUNT_WINDOW = 900;

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly SessionGuard $guard,
        private readonly TwoFactorChallenge $challenge,
        private readonly Validator $validator,
        private readonly SessionInterface $session,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/login', middleware: [RedirectAuthenticatedMiddleware::class])]
    public function showLogin(): Response
    {
        $status = $this->session->flashed('status');

        return $this->render('auth/login/index', [
            'vm' => new LoginViewModel(status: is_string($status) ? $status : null),
        ]);
    }

    #[Route('/login', methods: ['POST'], middleware: [LoginThrottleMiddleware::class, RedirectAuthenticatedMiddleware::class])]
    public function login(Request $request): Response
    {
        $input = ParsedBody::fromRequest($request);
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

        // Counted before the check, like every password budget here: once it
        // is spent, a right guess has to be refused the same as a wrong one.
        $throttled = $result->passes() && !$this->limiter->hit(
            mb_strtolower($username),
            new RateLimitPolicy('login-account', self::PER_ACCOUNT, self::PER_ACCOUNT_WINDOW),
        )->allowed;

        $user = $result->passes() && !$throttled ? $this->guard->validate($username, $password) : null;

        if ($user !== null && $this->challenge->required($user)) {
            $this->challenge->begin($user);

            return $this->respond->redirect('/two-factor');
        }

        if ($user !== null) {
            $this->guard->login($user);

            return $this->respond->redirect('/admin');
        }

        // A failed login is deliberately vague
        $errors = match (true) {
            !$result->passes() => $result->errors(),
            $throttled => ['credentials' => 'Too many sign-in attempts for this account. Wait a few minutes, or reset your password.'],
            default => ['credentials' => "Those credentials don't match our records."],
        };

        $route = Htmx::fromRequest($request)->isHtmx()
            ? 'auth/login/form'
            : 'auth/login/index';

        return $this->render(
            $route,
            ['vm' => new LoginViewModel($username, $errors)],
            $throttled ? Status::TooManyRequests : Status::UnprocessableEntity,
        );
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->guard->logout();
        return $this->respond->redirect('/login');
    }
}
