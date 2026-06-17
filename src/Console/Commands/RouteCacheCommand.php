<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Http\RouteCache;
use Hydra\Http\RouteScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the controller #[Route] attributes to the route cache file. This is
 * the sole writer of the cache — the web path only ever reads it (see
 * AppServiceProvider::compileRoutes). Run it at deploy time when ROUTE_CACHE is
 * on; re-run (or route:cache:clear) after changing any route.
 */
#[AsCommand(
    name: 'route:cache',
    description: 'Compile the controller routes to the route cache',
)]
final class RouteCacheCommand extends Command
{
    /**
     * @param list<class-string> $controllers
     */
    public function __construct(
        private readonly RouteCache $cache,
        private readonly array $controllers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $routes = (new RouteScanner)->scan($this->controllers);
        $this->cache->store($routes);

        $io->success(sprintf('Cached %d route(s).', count($routes)));

        return Command::SUCCESS;
    }
}
