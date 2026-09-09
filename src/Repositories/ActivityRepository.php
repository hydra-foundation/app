<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Activity;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Activity repository
 *
 * The write side of the activity log. Values are clipped to the column widths
 * here rather than at the call site: a 4 KB referer or a novelty user agent is
 * a fact about the request, not a reason to fail it.
 */
final class ActivityRepository
{
    private const LIMITS = [
        'username' => 64,
        'method' => 10,
        'path' => 512,
        'query' => 1024,
        'ip' => 45,
        'user_agent' => 512,
        'referer' => 512,
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function record(Activity $activity, ?string $at = null): void
    {
        $columns = 'user_id, username, method, path, query, status, duration_ms, ip, user_agent, referer';
        $values = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?';

        $params = [
            $activity->userId,
            $this->clip('username', $activity->username),
            $this->clip('method', $activity->method),
            $this->clip('path', $activity->path),
            $this->clip('query', $activity->query),
            $activity->status,
            $activity->durationMs,
            $this->clip('ip', $activity->ip),
            $this->clip('user_agent', $activity->userAgent),
            $this->clip('referer', $activity->referer),
        ];

        if ($at !== null) {
            $columns .= ', created_at';
            $values .= ', ?';
            $params[] = $at;
        }

        $this->db->execute("INSERT INTO activity ({$columns}) VALUES ({$values})", $params);
    }

    private function clip(string $column, ?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::LIMITS[$column]);
    }
}
