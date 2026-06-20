<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Database\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every migration on disk and whether it has been applied — a read-only
 * view of where the database stands relative to the migrations directory.
 */
#[AsCommand(
    name: 'migrate:status',
    description: 'Show which migrations have run and which are pending',
)]
final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->runner->status();

        if ($status === []) {
            $io->warning('No migrations found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Migration', 'Status'],
            array_map(
                static fn (array $row): array => [
                    $row['filename'],
                    $row['applied'] ? '<info>applied</info>' : '<comment>pending</comment>',
                ],
                $status,
            ),
        );

        return Command::SUCCESS;
    }
}
