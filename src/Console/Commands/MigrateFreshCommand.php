<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Database\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops every table and re-applies all migrations from scratch — a clean slate
 * for development.
 *
 * Destructive, so it is guarded the same way key:generate guards an existing
 * key: outside debug mode it refuses without --force, and either way it asks for
 * confirmation. The $debug flag is the dev signal Hydra already has (APP_DEBUG);
 * there is no separate APP_ENV.
 */
#[AsCommand(
    name: 'migrate:fresh',
    description: 'Drop all tables and re-run every migration',
)]
final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly bool $debug,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Run even when APP_DEBUG is off, and skip the confirmation prompt',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if (!$this->debug && !$force) {
            $io->error('APP_DEBUG is off — refusing to drop all tables. Re-run with --force if you really mean it.');
            return Command::FAILURE;
        }

        if (!$force && !$io->confirm('This will DROP every table and re-run all migrations. Continue?', false)) {
            $io->warning('Aborted.');
            return Command::FAILURE;
        }

        $applied = $this->runner->fresh();
        $io->success(sprintf('Database reset — applied %d migration(s).', count($applied)));

        return Command::SUCCESS;
    }
}
