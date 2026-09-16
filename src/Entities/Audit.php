<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * One recorded change to a row. The user is captured by id *and* by name, the
 * way {@see Activity} does it: user_id is ON DELETE SET NULL, so deleting an
 * account would otherwise erase who made every change it ever made. The name as
 * it was at the time is the record.
 */
final readonly class Audit
{
    public function __construct(
        public string $module,
        public string $tableId,
        public ?string $oldValue,
        public ?string $newValue,
        public ?int $userId,
        public ?string $username,
        public ?string $message
    ) {}
}
