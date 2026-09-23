<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\VerifyEmailMail;
use App\Repositories\UserRepository;
use App\View\VerificationBanner;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\EmailVerificationTokens;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proving an account's address. The link moves its token into the session
 * and redirects before anything is rendered, for the same reason a reset
 * link does: the activity log is readable by every signed-in user.
 *
 * Opening the link does not need a session of its own. The mail client that
 * opens it is often not the browser that is signed in.
 */
final class EmailVerificationController extends Controller
{
    private const SESSION_KEY = 'email_verification_token';

    /** Per account, and said out loud: the caller is signed in and already knows the address. */
    private const PER_ACCOUNT = 3;
    private const WINDOW = 3600;

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly UserRepository $users,
        private readonly EmailVerificationTokens $tokens,
        private readonly GuardInterface $guard,
        private readonly SessionInterface $session,
        private readonly MailerInterface $mailer,
        private readonly RateLimiter $limiter,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/verify-email', methods: ['POST'], middleware: [AuthenticateMiddleware::class])]
    public function send(): Response
    {
        $user = $this->guard->user();

        if ($user instanceof User && !$user->hasVerifiedEmail()) {
            $this->session->set(VerificationBanner::STATUS_KEY, $this->sendTo($user));
        }

        return $this->respond->redirect('/admin');
    }

    #[Route('/verify-email/{token}')]
    public function acceptLink(string $token): Response
    {
        $this->session->set(self::SESSION_KEY, $token);

        return $this->respond->redirect('/verify-email');
    }

    #[Route('/verify-email')]
    public function verify(): Response
    {
        $token = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);

        $user = is_string($token) ? $this->tokens->resolve($token) : null;

        if (!$user instanceof User) {
            return $this->render('auth/verify/invalid', [], Status::NotFound);
        }

        // Checked first so a second click on the same link reads as done
        // rather than as a failure: markVerified() keeps the first stamp.
        if ($user->hasVerifiedEmail() || $this->users->markVerified($user->id, $user->email)) {
            return $this->render('auth/verify/done', ['email' => $user->email]);
        }

        return $this->render('auth/verify/invalid', [], Status::NotFound);
    }

    private function sendTo(User $user): string
    {
        $policy = new RateLimitPolicy('verify-email', self::PER_ACCOUNT, self::WINDOW);

        if (!$this->limiter->hit((string) $user->id, $policy)->allowed) {
            return 'A link was sent recently. Check your inbox, or try again in an hour.';
        }

        $link = rtrim($this->app->url, '/') . '/verify-email/' . $this->tokens->create($user);

        try {
            $this->mailer->send(VerifyEmailMail::to($user, $link, $this->auth->verifyTtl, $this->app->name));
        } catch (Throwable $e) {
            $this->logger->error('Could not send an email verification link: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return 'The link could not be sent. Try again in a few minutes.';
        }

        return "A verification link is on its way to {$user->email}.";
    }
}
