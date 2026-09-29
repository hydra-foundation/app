<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tasks\PruneLockouts;
use DateTimeImmutable;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\Testing\ArrayLockoutStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A lockout that ended over a day ago has nothing left to tell anyone. */
#[CoversClass(PruneLockouts::class)]
final class PruneLockoutsTest extends TestCase
{
    public function test_lockouts_that_ended_over_a_day_ago_are_deleted(): void
    {
        $store = new ArrayLockoutStore;
        $store->record(self::endedAt('a', '2026-09-28 09:59:59'));
        $store->record(self::endedAt('b', '2026-09-28 10:00:00'));
        $store->record(self::endedAt('c', '2026-09-29 10:30:00'));

        (new PruneLockouts($store, new FrozenClock('2026-09-29 10:00:00')))->run();

        $this->assertNull($store->find('login', 'a'));
        $this->assertNotNull($store->find('login', 'b'), 'ended exactly a day ago: kept');
        $this->assertNotNull($store->find('login', 'c'));
    }

    private static function endedAt(string $identity, string $until): Lockout
    {
        $until = new DateTimeImmutable($until);

        return new Lockout('login', $identity, $until->modify('-10 minutes'), $until, 5, 600);
    }
}
