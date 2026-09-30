<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Widgets\Status;
use Hydra\Broadcast\Hub\HubStatus;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Whether pages are live: the SSE hub's own report, which it writes to Redis
 * every ten seconds and which expires half a minute after it stops. A hub
 * that is down is red, but only live updates are lost: every page still
 * works, so the card says so beside the fix.
 */
final class SseHubWidget implements PresenterInterface
{
    public function __construct(
        private readonly HubStatus $status,
        private readonly ClockInterface $clock,
    ) {}

    public function present(): array
    {
        try {
            $report = $this->status->read();
        } catch (Throwable $e) {
            return [
                'status' => Status::Down,
                'headline' => 'No answer',
                'caption' => "could not read the hub's status",
                'rows' => [],
                'note' => $e->getMessage(),
            ];
        }

        if ($report === null) {
            return [
                'status' => Status::Down,
                'headline' => 'Not running',
                'caption' => 'pages work, but nothing updates live',
                'rows' => [],
                'note' => 'Start it: docker compose up -d sse',
            ];
        }

        $rows = [
            ['label' => 'Streams', 'value' => (string) $report->connections],
            ['label' => 'Process', 'value' => (string) $report->pid],
        ];

        if (!$report->subscribed) {
            return [
                'status' => Status::Warning,
                'headline' => 'Reconnecting',
                'caption' => 'to Redis; open pages miss events meanwhile',
                'rows' => $rows,
                'note' => null,
            ];
        }

        return [
            'status' => Status::Ok,
            'headline' => 'Running',
            'caption' => 'up ' . Readable::duration(max(0, $this->clock->now()->getTimestamp() - $report->startedAt)),
            'rows' => $rows,
            'note' => null,
        ];
    }
}
