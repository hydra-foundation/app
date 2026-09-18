<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\Column;
use App\Console\Support\TableColumns;
use Hydra\Console\Commands\MakeClassCommand;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared base for the generators that write code shaped like a table:
 * make:source, make:module, make:repository, make:entity, make:source-test.
 *
 * Each of them otherwise begins with the same twenty seconds of copying a
 * column list out of a migration, which is exactly the transcription step that
 * put a wrong filter key into three hand-written sources. The columns come from
 * the database when one is reachable and from `--columns` when it is not, and
 * either way what lands on disk is the literal list, in code, for you to read.
 */
abstract class MakeFromTableCommand extends MakeClassCommand
{
    /** Columns the database issues rather than the application. */
    protected const NOT_WRITTEN = ['id', 'created_at', 'updated_at'];

    /** @var list<Column> */
    protected array $columns = [];

    protected string $table = '';

    public function __construct(
        private readonly string $targetDir,
        private readonly ?TableColumns $tables = null,
    ) {
        parent::__construct($targetDir);
    }

    /** The file this generator writes for an already-normalised class name. */
    public function target(string $class): string
    {
        return $this->targetDir . '/' . $class . '.php';
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'table',
            't',
            InputOption::VALUE_REQUIRED,
            'The table to read. Defaults to the plural of the name.',
        );
        $this->addOption(
            'columns',
            'c',
            InputOption::VALUE_REQUIRED,
            'A comma-separated column list, instead of reading the database.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->columns = $this->resolve($input);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            $io->note(
                'Pass --columns=id,name,created_at to write the declaration without a database, '
                . 'or bring one up and re-run. The generated list is meant to be edited either way.',
            );

            return self::FAILURE;
        }

        $this->table = $this->tableName($input);

        return parent::execute($input, $output);
    }

    /**
     * The table a name implies: "invoice" and "InvoiceSource" both mean
     * "invoices". Naive on purpose — it is a default for an option, and a
     * wrong guess is one word on the command line, not a silent mistake.
     */
    protected function tableName(InputInterface $input): string
    {
        $given = $input->getOption('table');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $input->getArgument('name')) ?? '');
        $base = trim($base, '_');

        foreach ([$this->suffix(), 'source', 'module', 'repository', 'entity'] as $tail) {
            $tail = strtolower($tail);
            if ($tail !== '' && str_ends_with($base, '_' . $tail)) {
                $base = substr($base, 0, -strlen('_' . $tail));
            } elseif ($tail !== '' && str_ends_with($base, $tail) && $base !== $tail) {
                $base = substr($base, 0, -strlen($tail));
            }
        }

        $base = trim($base, '_');

        if (str_ends_with($base, 's')) {
            return $base;
        }

        return str_ends_with($base, 'y')
            ? substr($base, 0, -1) . 'ies'
            : $base . 's';
    }

    /**
     * @return list<Column>
     * @throws RuntimeException
     */
    private function resolve(InputInterface $input): array
    {
        $given = $input->getOption('columns');

        if (is_string($given) && trim($given) !== '') {
            // An explicit list carries no types, so every column reads as text.
            // That is the honest result: the generator says what it was told and
            // guesses nothing further.
            return array_values(array_map(
                static fn (string $name): Column => new Column(trim($name), 'text'),
                array_filter(explode(',', $given), static fn (string $n): bool => trim($n) !== ''),
            ));
        }

        if ($this->tables === null) {
            throw new RuntimeException('No database connection is available to this command.');
        }

        return $this->tables->of($this->tableName($input));
    }

    /**
     * The columns a generator may put in front of a reader.
     *
     * Secrets are dropped here rather than in each generator, so the four of
     * them cannot disagree about it. What is left out is reported, never
     * silently: a column missing from a source is a thing to notice, and the
     * note is the whole difference between a safe default and a surprise.
     *
     * @return list<string>
     */
    protected function columnNames(): array
    {
        return array_values(array_map(
            static fn (Column $c): string => $c->name,
            array_filter($this->columns, static fn (Column $c): bool => !$c->isSecret()),
        ));
    }

    /** @return list<string> */
    protected function secretNames(): array
    {
        return array_values(array_map(
            static fn (Column $c): string => $c->name,
            array_filter($this->columns, static fn (Column $c): bool => $c->isSecret()),
        ));
    }

    /**
     * Said after every generator that reads a table, before its own note. A
     * secret left out is the one thing about the generated file that is not
     * visible in the generated file.
     */
    protected function reportSecrets(SymfonyStyle $io): void
    {
        $secrets = $this->secretNames();

        if ($secrets === []) {
            return;
        }

        $io->warning(sprintf(
            'Left out of the generated code: %s. A column that looks like a credential is not '
            . 'something to render into a list, a show screen and a CSV export by default. '
            . 'Add it back deliberately if it belongs, and write what happens to it by hand — '
            . 'UserSource hashes on the way in and never reads the column back.',
            implode(', ', $secrets),
        ));
    }

    /**
     * A PHP list literal, wrapped so a wide table does not write a 300-column
     * line. $indent is the indentation of the line the literal starts on: items
     * land one level in from it and the closing bracket lines up with it.
     *
     * @param list<string> $values
     */
    protected function listLiteral(array $values, string $indent = '            '): string
    {
        if ($values === []) {
            return '[]';
        }

        $quoted = array_map(static fn (string $v): string => "'{$v}'", $values);
        $inline = '[' . implode(', ', $quoted) . ']';

        if (strlen($inline) + strlen($indent) <= 96) {
            return $inline;
        }

        $lines = [];
        $line = '';
        foreach ($quoted as $value) {
            $candidate = $line === '' ? $value : $line . ', ' . $value;
            if (strlen($candidate) + strlen($indent) > 88) {
                $lines[] = $line . ',';
                $line = $value;

                continue;
            }
            $line = $candidate;
        }
        $lines[] = $line . ',';

        return "[\n" . $indent . '    ' . implode("\n" . $indent . '    ', $lines) . "\n" . $indent . ']';
    }
}
