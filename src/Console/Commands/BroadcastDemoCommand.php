<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Psr\Clock\ClockInterface;

/**
 * Publishes on `demo`, which the home page's live box listens to: the
 * smallest end-to-end check that broadcasting, the hub and the stream client
 * are all up. `./hydra broadcast:demo` with the home page open in two tabs.
 */
#[AsCommand(
    name: 'broadcast:demo',
    description: 'Publish on the demo topic, which the home page listens to',
)]
final class BroadcastDemoCommand extends Command
{
    public function __construct(
        private readonly BroadcasterInterface $broadcaster,
        private readonly ClockInterface $clock,
    ) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $this->broadcaster->publish('demo', 'changed', ['at' => $this->clock->now()->format(DATE_ATOM)]);

        $output->success('Published changed on demo. Every open page listening on demo refreshes.');

        return ExitCode::Success;
    }
}
