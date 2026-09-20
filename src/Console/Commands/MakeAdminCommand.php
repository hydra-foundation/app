<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates an admin module's whole set from one table: the source, the module
 * and the source's contract test, plus the entity and repository with
 * --repository. Nothing is written unless all of it can be.
 */
#[AsCommand(
    name: 'make:admin',
    description: 'Create an admin source, module and source test from one table',
)]
final class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly MakeSourceCommand $source,
        private readonly MakeModuleCommand $module,
        private readonly MakeSourceTestCommand $test,
        private readonly MakeEntityCommand $entity,
        private readonly MakeRepositoryCommand $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The thing the table holds, e.g. "invoice" or "invoices"');
        $this->addOption('table', 't', InputOption::VALUE_REQUIRED, 'The table to read. Defaults to the plural of the name.');
        $this->addOption('columns', 'c', InputOption::VALUE_REQUIRED, 'A comma-separated column list, instead of reading the database.');
        $this->addOption('writable', 'w', InputOption::VALUE_NONE, 'Write the source and its test with the create, update and delete contracts.');
        $this->addOption('repository', 'r', InputOption::VALUE_NONE, 'Also write the entity and repository, for code outside the admin.');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $singular = $this->singular($this->studly((string) $input->getArgument('name')));

        if ($singular === '') {
            $io->error('Name must contain at least one letter or digit.');

            return self::FAILURE;
        }

        $plural = $this->plural($singular);
        $table = $input->getOption('table');
        $table = is_string($table) && $table !== '' ? $table : $this->snake($plural);

        $plan = [
            [$this->source, $singular . 'Source', ['--writable' => $input->getOption('writable')]],
            [$this->module, $plural . 'Module', ['--source' => $singular . 'Source']],
            [$this->test, $singular . 'SourceTest', ['--writable' => $input->getOption('writable')]],
        ];

        if ($input->getOption('repository')) {
            $plan[] = [$this->entity, $singular, []];
            $plan[] = [$this->repository, $singular . 'Repository', []];
        }

        if (!$input->getOption('force')) {
            $existing = array_values(array_filter(
                array_map(static fn (array $step): string => $step[0]->target($step[1]), $plan),
                'is_file',
            ));

            if ($existing !== []) {
                $io->error('Nothing was written. Re-run with --force to overwrite what already exists.');
                $io->listing($existing);

                return self::FAILURE;
            }
        }

        $shared = array_filter([
            '--table' => $table,
            '--columns' => $input->getOption('columns'),
            '--force' => true,
        ]);

        foreach ($plan as [$command, $class, $options]) {
            $status = $command->run(
                new ArrayInput(['name' => $class, ...$shared, ...array_filter($options)]),
                $output,
            );

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        return self::SUCCESS;
    }

    private function studly(string $name): string
    {
        $class = str_replace(' ', '', ucwords(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name))));

        foreach (['SourceTest', 'Source', 'Module', 'Repository'] as $suffix) {
            if ($class !== $suffix && str_ends_with($class, $suffix)) {
                return substr($class, 0, -strlen($suffix));
            }
        }

        return $class;
    }

    private function singular(string $word): string
    {
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }

        if (preg_match('/(ss|x|ch|sh)es$/', $word)) {
            return substr($word, 0, -2);
        }

        return str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
    }

    private function plural(string $word): string
    {
        if (preg_match('/(s|x|ch|sh)$/', $word)) {
            return $word . 'es';
        }

        return str_ends_with($word, 'y') ? substr($word, 0, -1) . 'ies' : $word . 's';
    }

    private function snake(string $word): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $word));
    }
}
