<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use LogicException;

/**
 * A guard that answers one question and nothing else, for the services whose
 * whole dependency on authentication is "who is reading this page". The verbs
 * are unreachable rather than stubbed: a collaborator that logged somebody in
 * through this has taken a wrong turn and should say so.
 */
final class SignedInAs implements GuardInterface
{
    public function __construct(private readonly ?AuthenticatableInterface $user) {}

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function user(): ?AuthenticatableInterface
    {
        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    public function attempt(string $username, string $password): bool
    {
        throw new LogicException('This guard does not authenticate.');
    }

    public function login(AuthenticatableInterface $user): void
    {
        throw new LogicException('This guard does not authenticate.');
    }

    public function logout(): void
    {
        throw new LogicException('This guard does not authenticate.');
    }
}
