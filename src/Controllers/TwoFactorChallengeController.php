<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use App\ViewModels\TwoFactorViewModel;
use Hydra\Auth\TwoFactorChallenge;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Htmx;
use Hydra\Http\ParsedBody;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The code asked for after a right password, when the account has a second
 * factor. The budget for codes is the challenge's own, per account.
 */
final class TwoFactorChallengeController extends Controller
{
    /** Far above either kind of code, spaces and dashes included. */
    private const MAX_CODE = 64;

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly TwoFactorChallenge $challenge,
        private readonly SessionInterface $session,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/two-factor', middleware: [RedirectAuthenticatedMiddleware::class])]
    public function show(Request $request): Response
    {
        if ($this->challenge->pending() === null) {
            return $this->expired();
        }

        return $this->render('auth/two-factor/index', [
            'vm' => new TwoFactorViewModel(recovery: isset($request->getQueryParams()['recovery'])),
        ]);
    }

    #[Route('/two-factor', methods: ['POST'], middleware: [RedirectAuthenticatedMiddleware::class])]
    public function verify(Request $request): Response
    {
        if ($this->challenge->pending() === null) {
            return $this->expired();
        }

        $input = ParsedBody::fromRequest($request);
        $recovery = $input->string('method') === 'recovery';
        $code = substr(trim($input->string('code')), 0, self::MAX_CODE);

        $passed = $code !== '' && ($recovery ? $this->challenge->recover($code) : $this->challenge->verify($code));

        if ($passed) {
            return $this->respond->redirect('/admin');
        }

        $message = match (true) {
            $code === '' => $recovery ? 'Enter a recovery code.' : 'Enter the code from your authenticator app.',
            $recovery => 'That recovery code is not valid, or has been used.',
            default => 'That code is not valid. Wait for the next one and try again.',
        };

        return $this->render(
            Htmx::fromRequest($request)->isHtmx() ? 'auth/two-factor/form' : 'auth/two-factor/index',
            ['vm' => new TwoFactorViewModel($recovery, ['code' => $message])],
            Status::UnprocessableEntity,
        );
    }

    #[Route('/two-factor/cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        $this->challenge->cancel();

        return $this->respond->redirect('/login');
    }

    private function expired(): Response
    {
        $this->session->flash('status', 'Your sign-in timed out. Enter your password again.');

        return $this->respond->redirect('/login');
    }
}
