<?php

declare(strict_types=1);

namespace App\View;

use App\Entities\User;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Session\Contracts\SessionInterface;

/**
 * What the admin says to a signed-in user whose address is unproven. Asked
 * at render time, so building the view never reads the session.
 */
final readonly class VerificationBanner
{
    public const STATUS_KEY = 'verify_status';

    public function __construct(
        private GuardInterface $guard,
        private SessionInterface $session,
    ) {}

    /** The line to show, or null when there is nothing to verify. */
    public function message(): ?string
    {
        $user = $this->guard->user();

        if (!$user instanceof User || $user->hasVerifiedEmail()) {
            return null;
        }

        // Pulled rather than flashed: /admin redirects to its first screen,
        // and a flash would age out on the hop.
        $status = $this->session->get(self::STATUS_KEY);
        $this->session->remove(self::STATUS_KEY);

        return is_string($status) ? $status : "{$user->email} is not verified yet.";
    }
}
