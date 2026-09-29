<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Entities\Audit;
use App\Entities\User;
use App\Repositories\AuditRepository;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Events\PasswordReset;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The account changes made outside Settings, filed beside the ones made in it.
 * A reset changes the same password Settings does, and a spent recovery code is
 * one fewer way back in, so an account's history is not whole without either.
 *
 * Nobody is signed in when either happens — a reset is how someone locked out
 * gets back, a recovery code is how they finish signing in — so the account is
 * the event's own rather than the guard's.
 */
final class AuditAccountEventsListener
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly LoggerInterface $logger,
    ) {}

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->record($event->user, 'account.password_reset', null);
    }

    /** How many are left is no secret, and is what the owner needs to know next. */
    public function onRecoveryCodeUsed(RecoveryCodeUsed $event): void
    {
        $this->record(
            $event->user,
            'account.recovery_code_used',
            json_encode(['recovery_codes_remaining' => $event->remaining], JSON_THROW_ON_ERROR),
        );
    }

    /** Swallowed like every other audit write: the change has already happened. */
    private function record(AuthenticatableInterface $user, string $message, ?string $new): void
    {
        try {
            $id = (int) $user->getAuthIdentifier();

            $this->audit->record(new Audit(
                'users',
                (string) $id,
                null,
                $new,
                $id,
                $user instanceof User ? $user->username : null,
                $message,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Could not record audit: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
