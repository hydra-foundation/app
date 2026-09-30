<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\BroadcastDemoCommand;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BroadcastDemoCommand::class)]
final class BroadcastDemoCommandTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        yield 'broadcast:demo' => new BroadcastDemoCommand(new FakeBroadcaster, new FrozenClock);
    }

    public function test_it_publishes_on_the_demo_topic_and_says_so(): void
    {
        $broadcaster = new FakeBroadcaster;
        $output = new FakeOutput;

        $code = (new BroadcastDemoCommand($broadcaster, new FrozenClock('2026-09-30 15:30:00 UTC')))->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $code);
        $broadcaster->assertPublished('demo', 'changed', static fn ($e): bool => $e->data === ['at' => '2026-09-30T15:30:00+00:00'], times: 1);
        $this->assertSame(['Published changed on demo. Every open page listening on demo refreshes.'], $output->linesOfKind('success'));
    }
}
