<?php

declare(strict_types=1);

namespace App\Tests\Support\Jobs;

use Hydra\Queue\Contracts\JobInterface;

final class RecordingJob implements JobInterface
{
    /** @var list<array<array-key, mixed>> */
    public static array $handled = [];

    public function handle(array $payload): void
    {
        self::$handled[] = $payload;
    }
}
