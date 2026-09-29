<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * How recently a sign-in must have been seen to still be one: within PHP's
 * session idle limit, and a minute for the once-a-minute write of "last
 * seen". Older, its session may already be gone, so the list leaves it out and
 * the hourly prune deletes it; one answer, so the two never disagree.
 */
final class SignInWindow
{
    private const SLACK = 60;

    public function __construct(
        private readonly ClockInterface $clock,
        /** Seconds; PHP's session.gc_maxlifetime unless given. */
        private readonly ?int $idleSeconds = null,
    ) {}

    /** A sign-in last seen before this is over. */
    public function since(): DateTimeImmutable
    {
        $idle = $this->idleSeconds ?? (int) ini_get('session.gc_maxlifetime');

        return $this->clock->now()->modify('-' . ($idle + self::SLACK) . ' seconds');
    }
}
