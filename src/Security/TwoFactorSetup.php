<?php

declare(strict_types=1);

namespace App\Security;

use Hydra\Auth\Totp;
use Hydra\Session\Contracts\SessionInterface;

/**
 * The secret shown during setup, held in the session until a code from it
 * confirms that the authenticator has it. Nothing reaches the account before.
 */
final readonly class TwoFactorSetup
{
    private const KEY = '_2fa_setup';

    public function __construct(
        private SessionInterface $session,
        private Totp $totp,
    ) {}

    public function start(): string
    {
        $secret = $this->totp->secret();
        $this->session->set(self::KEY, $secret);

        return $secret;
    }

    public function secret(): ?string
    {
        $secret = $this->session->get(self::KEY);

        return is_string($secret) ? $secret : null;
    }

    public function forget(): void
    {
        $this->session->remove(self::KEY);
    }
}
