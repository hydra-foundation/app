<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds an empty migration file: {Ymd_His}_{slug}.sql with a header comment.
 *
 * The timestamp prefix is what orders migrations (the runner sorts lexically),
 * and it is the only generation Hydra does for migrations — the body stays a
 * blank .sql file the developer fills in by hand. No fluent builder, no stub DDL.
 */
#[AsCommand(
    name: 'make:migration',
    description: 'Create a new, empty timestamped .sql migration',
)]
final class MakeMigrationCommand extends Command
{
    public function __construct(private readonly string $migrationsPath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'A short description, e.g. "create posts table"',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $name */
        $name = $input->getArgument('name');
        $slug = $this->slug($name);

        if ($slug === '') {
            $io->error('Migration name must contain at least one letter or digit.');
            return Command::FAILURE;
        }

        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0o775, true) && !is_dir($this->migrationsPath)) {
            $io->error("Could not create migrations directory at {$this->migrationsPath}.");
            return Command::FAILURE;
        }

        $filename = date('Ymd_His') . '_' . $slug . '.sql';
        $path = $this->migrationsPath . '/' . $filename;

        file_put_contents($path, $this->template($name));

        $io->success("Created {$filename}");

        return Command::SUCCESS;
    }

    /** Lowercase, non-alphanumerics collapsed to single underscores. */
    private function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }

    private function template(string $name): string
    {
        return "-- Migration: {$name}\n"
            . '-- Created: ' . date('Y-m-d H:i:s') . "\n"
            . "-- Forward-only. One logical change per migration — MariaDB has no transactional DDL.\n\n";
    }
}
