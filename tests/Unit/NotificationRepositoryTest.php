<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\NotificationRepository;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Testing\NotificationStoreContractTestCase;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;

/** The skeleton's notices, in the notifications table, held to the admin's contract. */
#[CoversClass(NotificationRepository::class)]
final class NotificationRepositoryTest extends NotificationStoreContractTestCase
{
    private NotificationRepository $store;

    protected function setUp(): void
    {
        $this->store = new NotificationRepository(new PdoConnection(TestSchema::connect()));
    }

    protected function store(): NotificationStoreInterface
    {
        return $this->store;
    }

    protected function user(): int
    {
        return 7;
    }

    protected function otherUser(): int
    {
        return 8;
    }

    public function test_prune_deletes_only_read_notices_read_before_the_cutoff(): void
    {
        $old = $this->store->add(7, new Notice('old, read'), new DateTimeImmutable('2026-01-01 10:00:00'));
        $recent = $this->store->add(7, new Notice('read lately'), new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->store->add(7, new Notice('old, unread'), new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->store->markRead(7, $old, new DateTimeImmutable('2026-06-01 09:59:59'));
        $this->store->markRead(7, $recent, new DateTimeImmutable('2026-06-01 10:00:00'));

        $this->assertSame(1, $this->store->prune(new DateTimeImmutable('2026-06-01 10:00:00')));
        $this->assertSame(['old, unread', 'read lately'], array_map(static fn ($n) => $n->notice->title, $this->store->latest(7, 10)));
    }
}
