<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Bootstrap;
use App\Tests\Support\TestApp;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Broadcasting is wired: the real root picks the driver from .env, and the harness fakes it. */
#[CoversNothing]
final class BroadcastWiringTest extends TestCase
{
    public function test_the_harness_binds_the_fake(): void
    {
        $app = TestApp::boot();

        $fake = $app->get(FakeBroadcaster::class);
        $app->get(BroadcasterInterface::class)->publish('module.users', 'changed');

        $this->assertSame($fake, $app->get(BroadcasterInterface::class));
        $fake->assertPublished('module.users', 'changed', times: 1);
    }

    /**
     * Its own process: the real root parses a .env, and Environment exports
     * what it read to the process, where the next test would find it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_real_root_reads_the_driver_from_the_environment(): void
    {
        $dir = sys_get_temp_dir() . '/hydra-app-broadcast-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/.env', "BROADCAST_DRIVER=log\n");

        try {
            $broadcaster = Bootstrap::application($dir)->container()->get(BroadcasterInterface::class);
        } finally {
            unlink($dir . '/.env');
            rmdir($dir);
        }

        $this->assertInstanceOf(LogBroadcaster::class, $broadcaster);
    }
}
