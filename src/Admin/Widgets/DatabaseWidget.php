<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use App\Config\DbConfig;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Widgets\Status;
use Hydra\Database\Contracts\ConnectionInterface;
use Throwable;

/** Whether the database answers, how fast, and how much of it there is. */
final class DatabaseWidget implements PresenterInterface
{
    /** A round trip slower than this is worth looking at before it is worth alerting on. */
    private const SLOW_MS = 50;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DbConfig $config,
    ) {}

    public function present(): array
    {
        $sqlite = $this->config->driver === 'sqlite';
        $started = hrtime(true);

        try {
            $version = $this->db->selectOne($sqlite ? 'SELECT sqlite_version() AS v' : 'SELECT VERSION() AS v');
            $latency = (hrtime(true) - $started) / 1_000_000;
        } catch (Throwable $e) {
            return [
                'status' => Status::Down,
                'headline' => 'No answer',
                'caption' => sprintf('%s:%d', $this->config->host, $this->config->port),
                'rows' => [['label' => 'Driver', 'value' => $this->config->driver]],
                'note' => $e->getMessage(),
            ];
        }

        $slow = $latency > self::SLOW_MS;

        return [
            'status' => $slow ? Status::Warning : Status::Ok,
            'headline' => Readable::millis($latency),
            'caption' => $slow ? 'slow round trip' : 'responding',
            'rows' => array_values(array_filter([
                ['label' => 'Driver', 'value' => $this->config->driver],
                ['label' => 'Version', 'value' => (string) ($version['v'] ?? 'unknown')],
                ['label' => 'Database', 'value' => $sqlite ? basename($this->config->database) : $this->config->database],
                $this->size($sqlite),
                $this->tables($sqlite),
            ], static fn (?array $row): bool => $row !== null)),
            'note' => null,
        ];
    }

    /** @return array{label: string, value: string}|null */
    private function size(bool $sqlite): ?array
    {
        $row = $this->attempt(
            $sqlite
                ? 'SELECT (SELECT * FROM pragma_page_count()) * (SELECT * FROM pragma_page_size()) AS bytes'
                : 'SELECT SUM(data_length + index_length) AS bytes
                   FROM information_schema.tables WHERE table_schema = DATABASE()',
        );

        $bytes = $row['bytes'] ?? null;

        return $bytes === null ? null : ['label' => 'Size', 'value' => (string) Readable::bytes((float) $bytes)];
    }

    /** @return array{label: string, value: string}|null */
    private function tables(bool $sqlite): ?array
    {
        $row = $this->attempt(
            $sqlite
                ? "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
                : 'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()',
        );

        return $row === null ? null : ['label' => 'Tables', 'value' => number_format((int) $row['n'])];
    }

    /**
     * A figure the card can do without: a grant that covers the application's
     * own tables need not cover information_schema.
     *
     * @return array<string, mixed>|null
     */
    private function attempt(string $sql): ?array
    {
        try {
            return $this->db->selectOne($sql);
        } catch (Throwable) {
            return null;
        }
    }
}
