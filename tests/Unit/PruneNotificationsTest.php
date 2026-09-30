<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\NotificationRepository;
use App\Tasks\PruneNotifications;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use Hydra\Admin\Notifications\Notice;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PruneNotifications::class)]
final class PruneNotificationsTest extends TestCase
{
    public function test_notices_read_over_ninety_days_ago_go_and_unread_ones_stay(): void
    {
        $store = new NotificationRepository(new PdoConnection(TestSchema::connect()));
        $gone = $store->add(7, new Notice('gone'), new DateTimeImmutable('2026-01-01 00:00:00'));
        $kept = $store->add(7, new Notice('kept'), new DateTimeImmutable('2026-01-01 00:00:00'));
        $store->add(7, new Notice('unread'), new DateTimeImmutable('2026-01-01 00:00:00'));
        $store->markRead(7, $gone, new DateTimeImmutable('2026-07-02 09:59:59'));
        $store->markRead(7, $kept, new DateTimeImmutable('2026-07-02 10:00:00'));

        (new PruneNotifications($store, new FrozenClock('2026-09-30 10:00:00')))->run();

        $this->assertSame(['unread', 'kept'], array_map(static fn ($n) => $n->notice->title, $store->latest(7, 10)));
    }
}
