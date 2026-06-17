<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Http\RouteCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the route cache file. After this the web path falls back to scanning
 * on every request until route:cache is run again. Clearing an already-cold
 * cache is success, not an error.
 */
#[AsCommand(
    name: 'route:cache:clear',
    description: 'Delete the compiled route cache',
)]
final class RouteCacheClearCommand extends Command
{
    public function __construct(private readonly RouteCache $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->success($this->cache->clear()
            ? 'Route cache cleared.'
            : 'Route cache was already clear.');

        return Command::SUCCESS;
    }
}
