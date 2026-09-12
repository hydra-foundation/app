<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * One recorded request. The user is captured by id *and* by name: the id links
 * back to a live account, the name survives a rename or a deletion, so an old
 * row still says who was there.
 */
final readonly class Activity
{
    public function __construct(
        public ?int $userId,
        public ?string $username,
        public string $method,
        public string $path,
        public string $query,
        public int $status,
        public int $durationMs,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $referer,
    ) {}
}
