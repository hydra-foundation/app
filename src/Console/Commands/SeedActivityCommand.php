<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Entities\Activity;
use App\Repositories\ActivityRepository;
use Hydra\Database\Contracts\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed activity command
 *
 * Backfills plausible traffic so the Activity module has something to page,
 * sort and filter before the site has earned any real visitors. Rows are
 * attributed to the accounts that actually exist, plus a share of guests.
 */
#[AsCommand(
    name: 'activity:seed',
    description: 'Fill the activity table with fabricated request history',
)]
final class SeedActivityCommand extends Command
{
    private const PATHS = [
        ['GET', '/', [200]],
        ['GET', '/login', [200]],
        ['POST', '/login', [302, 422]],
        ['POST', '/logout', [302]],
        ['GET', '/admin/dashboard', [200, 302]],
        ['GET', '/admin/users', [200, 403]],
        ['GET', '/admin/activity', [200, 403]],
        ['GET', '/does-not-exist', [404]],
        ['GET', '/admin/reports', [500]],
    ];

    private const QUERIES = ['', '', '', 'page=2', 'sort=id&dir=asc', 'q=admin', 'role=admin'];

    private const AGENTS = [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
        'curl/8.11.0',
    ];

    public function __construct(
        private readonly ActivityRepository $activity,
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::OPTIONAL, 'How many rows to insert', '250');
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Spread the rows over this many days back', '14');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = (int) $input->getArgument('count');
        $days = (int) $input->getOption('days');

        if ($count < 1 || $days < 1) {
            $io->error('Count and days must both be at least 1.');

            return Command::FAILURE;
        }

        $users = $this->db->select('SELECT id, username FROM users ORDER BY id');
        $now = time();
        $window = $days * 86400;

        $io->progressStart($count);

        for ($i = 0; $i < $count; $i++) {
            [$method, $path, $statuses] = self::PATHS[array_rand(self::PATHS)];
            $user = $users !== [] && random_int(1, 10) > 3 ? $users[array_rand($users)] : null;

            $this->activity->record(
                new Activity(
                    userId: $user === null ? null : (int) $user['id'],
                    username: $user === null ? null : (string) $user['username'],
                    method: $method,
                    path: $path,
                    query: $method === 'GET' ? self::QUERIES[array_rand(self::QUERIES)] : '',
                    status: $statuses[array_rand($statuses)],
                    durationMs: random_int(1, 900),
                    ip: '192.0.2.' . random_int(1, 254),
                    userAgent: self::AGENTS[array_rand(self::AGENTS)],
                    referer: random_int(1, 3) === 1 ? 'http://hydra.localhost/admin/dashboard' : null,
                ),
                date('Y-m-d H:i:s', $now - random_int(0, $window)),
            );

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success("Seeded {$count} activity rows across the last {$days} days.");

        return Command::SUCCESS;
    }
}
