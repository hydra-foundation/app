<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Argument;
use Hydra\Console\ArrayInput;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;

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
    ) {}

    public function arguments(): array
    {
        return [Argument::required('name', 'The thing the table holds, e.g. "invoice" or "invoices"')];
    }

    public function options(): array
    {
        return [
            Option::value('table', 't', 'The table to read. Defaults to the plural of the name.'),
            Option::value('columns', 'c', 'A comma-separated column list, instead of reading the database.'),
            Option::flag('writable', 'w', 'Write the source and its test with the create, update and delete contracts.'),
            Option::flag('repository', 'r', 'Also write the entity and repository, for code outside the admin.'),
            Option::flag('force', 'f', 'Overwrite existing files'),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {

        $singular = $this->singular($this->studly($input->argument('name')));

        if ($singular === '') {
            $output->error('Name must contain at least one letter or digit.');

            return ExitCode::Failure;
        }

        $plural = $this->plural($singular);
        $table = $input->option('table');
        $table = $table !== '' ? $table : $this->snake($plural);

        // Each step: the generator, the class it writes, the options only it
        // takes, and the flags only it takes.
        $writable = $input->flag('writable') ? ['writable'] : [];

        $plan = [
            [$this->source, $singular . 'Source', [], $writable],
            [$this->module, $plural . 'Module', ['source' => $singular . 'Source'], []],
            [$this->test, $singular . 'SourceTest', [], $writable],
        ];

        if ($input->flag('repository')) {
            $plan[] = [$this->entity, $singular, [], []];
            $plan[] = [$this->repository, $singular . 'Repository', [], []];
        }

        if (!$input->flag('force')) {
            $existing = array_values(array_filter(
                array_map(static fn (array $step): string => $step[0]->target($step[1]), $plan),
                'is_file',
            ));

            if ($existing !== []) {
                $output->error('Nothing was written. Re-run with --force to overwrite what already exists.');
                $output->listing($existing);

                return ExitCode::Failure;
            }
        }

        // --force is passed on unconditionally: whether anything may be
        // overwritten was decided once, above, for the set as a whole.
        $shared = array_filter(['table' => $table, 'columns' => $input->option('columns')]);

        foreach ($plan as [$command, $class, $options, $flags]) {
            $status = $command->execute(
                ArrayInput::forCommand(
                    $command,
                    arguments: ['name' => $class],
                    options: [...$shared, ...$options],
                    flags: ['force', ...$flags],
                ),
                $output,
            );

            if ($status !== ExitCode::Success) {
                return $status;
            }
        }

        return ExitCode::Success;
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
