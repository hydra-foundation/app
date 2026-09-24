<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\Audit;
use App\Entities\User;
use App\Jobs\SendAddressChangedNotice;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use Hydra\Auth\EmailChangeTokens;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Confirming an address change asked for in Settings. The link moves its token
 * into the session before anything renders, like the verify and reset links,
 * and needs no session of its own for the same reason: the mail client that
 * opens it is often not the signed-in browser.
 */
final class EmailChangeController extends Controller
{
    private const SESSION_KEY = 'email_change_token';

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly UserRepository $users,
        private readonly EmailChangeTokens $tokens,
        private readonly SessionInterface $session,
        private readonly QueueInterface $queue,
        private readonly AuditRepository $audit,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/change-email/{token}')]
    public function acceptLink(string $token): Response
    {
        $this->session->set(self::SESSION_KEY, $token);

        return $this->respond->redirect('/change-email');
    }

    #[Route('/change-email')]
    public function change(): Response
    {
        $token = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);

        $change = is_string($token) ? $this->tokens->resolve($token) : null;
        $user = $change?->user;

        if (!$user instanceof User) {
            return $this->render('auth/change/invalid', [], Status::NotFound);
        }

        $owner = $this->users->byEmail($change->email);

        if ($owner !== null && $owner->id !== $user->id) {
            return $this->render('auth/change/taken', [], 409);
        }

        if (!$this->users->changeEmail($user->id, $user->email, $change->email)) {
            return $this->render('auth/change/invalid', [], Status::NotFound);
        }

        $this->queue->push(SendAddressChangedNotice::class, ['user' => $user->id, 'previous' => $user->email]);
        $this->auditChange($user);

        return $this->render('auth/change/done', ['email' => $change->email]);
    }

    /** Swallowed like the password change's audit: the address has already moved. */
    private function auditChange(User $user): void
    {
        try {
            $this->audit->record(new Audit(
                'users',
                (string) $user->id,
                null,
                null,
                $user->id,
                $user->username,
                'account.email_changed',
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Could not record audit: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
