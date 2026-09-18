<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\Column;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates the contract test for an admin source in tests/Unit: the fixture
 * the framework's published contract case asks for, and nothing else.
 *
 * The fixture is built from the same column reading make:source makes, so the
 * term it searches for lands in a column the generated source searches, and the
 * rows it writes are the columns the generated source writes.
 */
#[AsCommand(
    name: 'make:source-test',
    description: 'Create the contract test for an admin source in tests/Unit',
)]
final class MakeSourceTestCommand extends MakeFromTableCommand
{
    /** Distinct, and free of %, _ and !, which the wildcard cases rely on. */
    private const NAMES = ['ada', 'grace', 'alan', 'edsger', 'barbara', 'linus', 'margaret'];
    private const WORDS = ['one', 'two', 'three', 'four', 'five', 'six', 'seven'];
    private const ROWS = 5;
    private const SEARCH = 'grace';

    private bool $writable = false;

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'writable',
            'w',
            InputOption::VALUE_NONE,
            'Hold the source to the write contracts as well, for a source made with make:source --writable.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->writable = (bool) $input->getOption('writable');

        return parent::execute($input, $output);
    }

    protected function nameHint(): string
    {
        return 'The source the test is for, e.g. "invoice" or "InvoiceSourceTest"';
    }

    protected function suffix(): string
    {
        return 'SourceTest';
    }

    protected function stub(string $class): string
    {
        $source = substr($class, 0, -strlen('Test'));
        $case = $this->writable ? 'WritableSourceContractTestCase' : 'RowSourceContractTestCase';

        $inserted = array_values(array_filter(
            $this->columns,
            static fn (Column $c): bool => !$c->isKey(),
        ));

        $rows = implode("\n", array_map(
            fn (int $n): string => '            ' . $this->rowLiteral($inserted, $n) . ',',
            range(0, self::ROWS - 1),
        ));

        $insert = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(static fn (Column $c): string => $c->name, $inserted)),
            implode(', ', array_fill(0, count($inserted), '?')),
        );

        $hooks = implode('', array_map(
            static fn (string $hook): string => "\n\n" . $hook,
            array_filter([$this->searchHook(), $this->writable ? $this->writeHooks() : null]),
        ));
        $count = self::ROWS;

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Tests\\Unit;

        use App\\Admin\\Sources\\{$source};
        use App\\Tests\\Support\\TestSchema;
        use Hydra\\Admin\\Contracts\\SourceInterface;
        use Hydra\\Admin\\Testing\\{$case};
        use Hydra\\Database\\PdoConnection;
        use PHPUnit\\Framework\\Attributes\\CoversClass;

        #[CoversClass({$source}::class)]
        final class {$class} extends {$case}
        {
            private {$source} \$source;

            protected function setUp(): void
            {
                \$pdo = TestSchema::connect();

                \$rows = [
        {$rows}
                ];

                foreach (\$rows as \$row) {
                    \$pdo->prepare('{$insert}')->execute(\$row);
                }

                \$this->source = new {$source}(new PdoConnection(\$pdo));
            }

            protected function source(): SourceInterface
            {
                return \$this->source;
            }

            protected function rowCount(): int
            {
                return {$count};
            }

            protected function sortColumn(): string
            {
                return '{$this->sortColumn()}';
            }{$hooks}
        }

        PHP;
    }

    private function sortColumn(): string
    {
        $names = array_map(static fn (Column $c): string => $c->name, $this->columns);

        return in_array('id', $names, true) ? 'id' : ($names[0] ?? 'id');
    }

    /** The first column make:source makes searchable carries the names. */
    private function searchColumn(): ?string
    {
        foreach ($this->columns as $column) {
            if ($column->isText() && !$column->isKey() && !$column->isSecret()) {
                return $column->name;
            }
        }

        return null;
    }

    private function searchHook(): ?string
    {
        if ($this->searchColumn() === null) {
            return null;
        }

        $term = self::SEARCH;

        return <<<PHP
            protected function searchMatchingSomeRows(): string
            {
                return '{$term}';
            }
        PHP;
    }

    private function writeHooks(): string
    {
        $written = array_values(array_filter(
            $this->columns,
            static fn (Column $c): bool => !$c->isSecret() && !in_array($c->name, self::NOT_WRITTEN, true),
        ));

        $new = $this->arrayLiteral($written, self::ROWS);
        $edited = $this->arrayLiteral($written, self::ROWS + 1);

        return <<<PHP
            protected function newRow(): array
            {
                return {$new};
            }

            protected function editedRow(): array
            {
                return {$edited};
            }
        PHP;
    }

    /** @param list<Column> $columns */
    private function rowLiteral(array $columns, int $n): string
    {
        return '[' . implode(', ', array_map(fn (Column $c): string => $this->value($c, $n), $columns)) . ']';
    }

    /** @param list<Column> $columns */
    private function arrayLiteral(array $columns, int $n): string
    {
        if ($columns === []) {
            return '[]';
        }

        return '[' . implode(', ', array_map(
            fn (Column $c): string => sprintf("'%s' => %s", $c->name, $this->value($c, $n)),
            $columns,
        )) . ']';
    }

    /** A value for row $n, distinct from every other row's in the same column. */
    private function value(Column $column, int $n): string
    {
        if ($column->isSecret()) {
            return "'hashed-secret'";
        }

        if ($column->name === $this->searchColumn()) {
            return "'" . self::NAMES[$n] . "'";
        }

        if ($column->isTemporal() || str_ends_with($column->name, '_at')) {
            return sprintf("'2026-01-0%d 09:00:00'", $n + 1);
        }

        if (str_starts_with($column->name, 'is_')) {
            return (string) ($n % 2);
        }

        if ($this->isInteger($column)) {
            return (string) ($n + 1);
        }

        return "'value " . self::WORDS[$n] . "'";
    }

    private function isInteger(Column $column): bool
    {
        return preg_match('/int/i', $column->type) === 1;
    }

    /** The sqlite twin of the table, for tests/Support/TestSchema.php. */
    private function schema(): string
    {
        $lines = array_map(
            fn (Column $c): string => match (true) {
                $c->isKey() => '    id INTEGER PRIMARY KEY AUTOINCREMENT',
                $this->isInteger($c) => "    {$c->name} INTEGER NULL",
                default => "    {$c->name} TEXT NULL",
            },
            $this->columns,
        );

        return "CREATE TABLE {$this->table} (\n" . implode(",\n", $lines) . "\n)";
    }

    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $io->note(
            "The test reads {$this->table} from tests/Support/TestSchema.php, never the migrations. "
            . 'If it is not mirrored there yet, this is a first draft of the sqlite twin — tighten '
            . 'NOT NULL, defaults and uniqueness to match the migration:',
        );
        $io->writeln($this->schema());
    }
}
