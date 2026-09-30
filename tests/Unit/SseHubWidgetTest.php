<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Widgets\SseHubWidget;
use Hydra\Admin\Widgets\Status;
use Hydra\Broadcast\Hub\HubReport;
use Hydra\Broadcast\Hub\HubStatus;
use Hydra\Broadcast\Testing\FakeHubStatus;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Whether pages are live: the hub's own report, read off Redis. */
#[CoversClass(SseHubWidget::class)]
final class SseHubWidgetTest extends TestCase
{
    private FrozenClock $clock;
    private FakeHubStatus $status;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-30 12:00:00 UTC');
        $this->status = new FakeHubStatus;
    }

    public function test_a_running_hub_is_ok_with_its_streams_and_uptime(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->status->publish(new HubReport(42, $now - 7200, 12, true, $now), 30);

        $card = (new SseHubWidget($this->status, $this->clock))->present();

        $this->assertSame(Status::Ok, $card['status']);
        $this->assertSame('Running', $card['headline']);
        $this->assertSame('up 2h 0m', $card['caption']);
        $this->assertSame([['label' => 'Streams', 'value' => '12'], ['label' => 'Process', 'value' => '42']], $card['rows']);
        $this->assertNull($card['note']);
    }

    public function test_a_hub_cut_off_from_redis_is_a_warning(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->status->publish(new HubReport(42, $now - 60, 3, false, $now), 30);

        $card = (new SseHubWidget($this->status, $this->clock))->present();

        $this->assertSame(Status::Warning, $card['status']);
        $this->assertSame('Reconnecting', $card['headline']);
        $this->assertSame('to Redis; open pages miss events meanwhile', $card['caption']);
    }

    public function test_no_hub_is_down_with_the_fix(): void
    {
        $card = (new SseHubWidget($this->status, $this->clock))->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('Not running', $card['headline']);
        $this->assertSame('pages work, but nothing updates live', $card['caption']);
        $this->assertSame('Start it: docker compose up -d sse', $card['note']);
    }

    public function test_a_status_that_cannot_be_read_is_down_with_the_reason(): void
    {
        $status = new class implements HubStatus {
            public function publish(HubReport $report, int $ttl): void {}

            public function clear(): void {}

            public function read(): ?HubReport
            {
                throw new RuntimeException('Could not connect to Redis at redis:6379.');
            }
        };

        $card = (new SseHubWidget($status, $this->clock))->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('No answer', $card['headline']);
        $this->assertSame('Could not connect to Redis at redis:6379.', $card['note']);
    }
}
