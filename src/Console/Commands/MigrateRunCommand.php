<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies every pending .sql migration in order. Forward-only: migrations
 * already recorded in the `migrations` table are skipped, so re-running is safe.
 */
#[AsCommand(
    name: 'migrate:run',
    description: 'Apply all pending migrations',
)]
final class MigrateRunCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $applied = $this->runner->run();

        if ($applied === []) {
            $io->success('Nothing to migrate — already up to date.');
            return Command::SUCCESS;
        }

        foreach ($applied as $filename) {
            $io->writeln("  <info>✓</info> {$filename}");
        }
        $io->success(sprintf('Applied %d migration(s).', count($applied)));

        return Command::SUCCESS;
    }
}
