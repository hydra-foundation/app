<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\User;
use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use App\Jobs\SendPasswordResetLink;
use App\Repositories\UserRepository;
use App\ViewModels\ForgotPasswordViewModel;
use App\ViewModels\ResetPasswordViewModel;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Events\PasswordReset;
use Hydra\Auth\PasswordResetTokens;
use Hydra\Http\Attributes\Route;
use Hydra\Http\ParsedBody;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Validation\Rules\Confirmed;
use Hydra\Validation\Rules\Email;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Forgotten passwords. The emailed link carries the token only as far as the
 * first request, which moves it into the session and redirects to a clean
 * URL: every signed-in user can read the activity log, and a path or Referer
 * holding a live token there is somebody else's account.
 */
final class PasswordResetController extends Controller
{
    private const SESSION_KEY = 'password_reset_token';

    /** The same ceiling the login form holds a password to. */
    private const MAX_PASSWORD = 4096;

    /**
     * Refused with a 429: a client asking for many links is a client
     * enumerating, and the refusal says nothing about any one address.
     */
    private const PER_CLIENT = 5;

    /**
     * Skipped in silence: the refusal would be the only difference between
     * one address and the next, and this is what stops the form mail-bombing
     * somebody else's inbox.
     */
    private const PER_ADDRESS = 3;

    private const WINDOW = 3600;

    private const SENT = 'If an account uses that address, a reset link is on its way.';

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly UserRepository $users,
        private readonly PasswordResetTokens $tokens,
        private readonly HasherInterface $hasher,
        private readonly SessionInterface $session,
        private readonly QueueInterface $queue,
        private readonly RateLimiter $limiter,
        private readonly Validator $validator,
        private readonly EventDispatcherInterface $events,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/forgot-password', middleware: [RedirectAuthenticatedMiddleware::class])]
    public function showRequest(): Response
    {
        return $this->render('auth/forgot/index', ['vm' => new ForgotPasswordViewModel]);
    }

    #[Route('/forgot-password', methods: ['POST'], middleware: [RedirectAuthenticatedMiddleware::class])]
    public function sendLink(Request $request): Response
    {
        $email = trim(ParsedBody::fromRequest($request)->string('email'));

        $result = $this->validator->validate(['email' => $email], [
            'email' => [new Required('Enter your email address.'), new Email],
        ]);

        if (!$result->passes()) {
            return $this->render(
                'auth/forgot/index',
                ['vm' => new ForgotPasswordViewModel($email, $result->errors())],
                Status::UnprocessableEntity,
            );
        }

        $this->limiter->enforce($request, new RateLimitPolicy('password-reset', self::PER_CLIENT, self::WINDOW));

        $perAddress = new RateLimitPolicy('password-reset-address', self::PER_ADDRESS, self::WINDOW);

        // Queued whether or not the address has an account, which the job
        // finds out: looking it up here is what made a known address slower.
        if ($this->limiter->hit(strtolower($email), $perAddress)->allowed) {
            $this->queue->push(SendPasswordResetLink::class, ['email' => $email]);
        }

        return $this->render('auth/forgot/index', ['vm' => new ForgotPasswordViewModel(status: self::SENT)]);
    }

    #[Route('/reset-password/{token}')]
    public function acceptLink(string $token): Response
    {
        $this->session->set(self::SESSION_KEY, $token);

        return $this->respond->redirect('/reset-password');
    }

    #[Route('/reset-password')]
    public function showReset(): Response
    {
        $user = $this->pendingUser();

        return $user === null
            ? $this->render('auth/reset/invalid', [], Status::NotFound)
            : $this->render('auth/reset/index', ['vm' => new ResetPasswordViewModel($user->username)]);
    }

    #[Route('/reset-password', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        // Resolved again rather than trusted from the form render: the
        // password may have changed since, which is what spends the token.
        $user = $this->pendingUser();

        if ($user === null) {
            return $this->render('auth/reset/invalid', [], Status::NotFound);
        }

        $input = ParsedBody::fromRequest($request);
        $data = [
            'password' => $input->string('password'),
            'password_confirmation' => $input->string('password_confirmation'),
        ];

        $result = $this->validator->validate($data, [
            'password' => [
                new Required('Choose a new password.'),
                new MinLength(8),
                new MaxLength(self::MAX_PASSWORD),
                new Confirmed(message: 'The passwords do not match.'),
            ],
        ]);

        if (!$result->passes()) {
            return $this->render(
                'auth/reset/index',
                ['vm' => new ResetPasswordViewModel($user->username, $result->errors())],
                Status::UnprocessableEntity,
            );
        }

        $this->users->updatePassword($user->id, $this->hasher->hash($data['password']));
        $this->session->remove(self::SESSION_KEY);
        $this->events->dispatch(new PasswordReset($user));
        $this->session->flash('status', 'Your password has been reset. Sign in with the new one.');

        return $this->respond->redirect('/login');
    }

    private function pendingUser(): ?User
    {
        $token = $this->session->get(self::SESSION_KEY);
        $user = is_string($token) ? $this->tokens->resolve($token) : null;

        return $user instanceof User ? $user : null;
    }
}
