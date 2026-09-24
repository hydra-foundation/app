<?php

declare(strict_types=1);

namespace App\Security;

use App\Entities\User;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;

/**
 * "Enter your current password" on every settings form, spending one budget,
 * since every form guesses the same secret. Counted before the check, not after
 * a miss: once the budget is spent, a right guess has to be refused the same as
 * a wrong one.
 */
final readonly class CurrentPassword
{
    private const CHECKS = 5;

    private const WINDOW = 3600;

    public function __construct(
        private HasherInterface $hasher,
        private RateLimiter $limiter,
    ) {}

    /** @throws TooManyRequestsException once the budget is spent */
    public function matches(User $user, string $password): bool
    {
        $status = $this->limiter->hit((string) $user->id, new RateLimitPolicy('account-password', self::CHECKS, self::WINDOW));

        if (!$status->allowed) {
            throw new TooManyRequestsException($status->retryAfter);
        }

        return $this->hasher->verify($password, $user->passwordHash);
    }
}
