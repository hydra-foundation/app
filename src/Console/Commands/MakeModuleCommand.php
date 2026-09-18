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
 * Generates an admin module in App\Admin\Modules: the definition, its fields
 * and its screens.
 *
 * The fields are written to match the source's own declaration rather than the
 * table, which is the whole reason this is worth generating. A `->searchable()`
 * field the source does not search is a column the box never looks in, and a
 * `->filterable()` one it does not filter by is a select that narrows nothing —
 * neither says anything at runtime, and both are what you get from writing the
 * two files half an hour apart. Written together they start in agreement, and
 * `admin:check` is what keeps them there.
 */
#[AsCommand(
    name: 'make:module',
    description: 'Create an admin module in App\\Admin\\Modules',
)]
final class MakeModuleCommand extends MakeFromTableCommand
{
    private ?string $source = null;

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'source',
            's',
            InputOption::VALUE_REQUIRED,
            'The source class it reads. Defaults to the singular of the name, e.g. InvoiceSource.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $given = $input->getOption('source');
        $this->source = is_string($given) && $given !== '' ? $given : null;

        return parent::execute($input, $output);
    }

    protected function nameHint(): string
    {
        return 'The module name, e.g. "invoices" or "InvoicesModule"';
    }

    protected function suffix(): string
    {
        return 'Module';
    }

    protected function stub(string $class): string
    {
        // The slug is the module's, not the table's. It is the URL the admin
        // mounts this at, and a module reading a table under another name — a
        // second view onto one table is the ordinary case — still answers at
        // its own path.
        $slug = $this->slug($class);
        $title = ucfirst(str_replace('_', ' ', $slug));
        $source = $this->sourceClass($class);
        $fields = $this->fields();
        $sort = $this->defaultSort();
        $row = $this->singular($title);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Admin\\Modules;

        use App\\Admin\\Sources\\{$source};
        use App\\Authorization\\AccessAdmin;
        use Hydra\\Admin\\Contracts\\ModuleInterface;
        use Hydra\\Admin\\Definition;
        use Hydra\\Admin\\Field;
        use Hydra\\Admin\\Screens\\ShowScreen;

        /**
         * The {$slug} module.
         *
         * A list screen is not declared because a module with a source and fields
         * gets one; everything else is a decision, which is why it is written out.
         */
        final class {$class} implements ModuleInterface
        {
            public function define(): Definition
            {
                return Definition::make('{$slug}')
                    ->title('{$title}')
                    ->ability(AccessAdmin::class)
                    ->source({$source}::class)
                    ->perPage(25)
                    ->defaultSort({$sort})
                    ->fields(
        {$fields}
                    )
                    ->screens(
                        ShowScreen::make()->title('{$row}'),
                    );
            }
        }

        PHP;
    }

    /** The module's own name in the URL: InvoicesModule → "invoices". */
    private function slug(string $class): string
    {
        $base = substr($class, 0, -strlen($this->suffix()));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }

    /**
     * The source a module of this name reads. It is a guess at a class name and
     * nothing checks it here, but a wrong one fails at boot with the registry
     * saying so, which is the right place for it to fail.
     */
    private function sourceClass(string $class): string
    {
        if ($this->source !== null) {
            return $this->source;
        }

        $base = substr($class, 0, -strlen($this->suffix()));

        // A module is plural ("Invoices") and a source is singular ("Invoice").
        if (str_ends_with($base, 'ies')) {
            $base = substr($base, 0, -3) . 'y';
        } elseif (str_ends_with($base, 's') && !str_ends_with($base, 'ss')) {
            $base = substr($base, 0, -1);
        }

        return $base . 'Source';
    }

    /**
     * One Field line per column, in the table's own order.
     *
     * The type decides the constructor: an id column gets Field::id(), a time
     * gets Field::datetime(), everything else is text. `->sortable()` goes on
     * every field because the generated source sorts by every column; the two
     * are narrowed together or not at all, which is the pairing this exists to
     * keep.
     */
    private function fields(): string
    {
        $indent = '                ';
        $lines = [];

        foreach ($this->columns as $column) {
            if ($column->isSecret()) {
                continue;
            }

            $lines[] = $indent . $this->field($column) . ',';
        }

        return implode("\n", $lines);
    }

    private function field(Column $column): string
    {
        if ($column->isKey()) {
            return "Field::id()->labelled('ID')->sortable()";
        }

        $label = ucfirst(str_replace('_', ' ', $column->name));
        $call = $column->isTemporal()
            ? "Field::datetime('{$column->name}')"
            : "Field::text('{$column->name}')";

        $field = sprintf("%s->labelled('%s')->sortable()", $call, $label);

        if ($column->isText()) {
            $field .= '->searchable()';
        }

        if ($column->looksFilterable()) {
            $field .= '->filterable()';
        }

        return $field;
    }

    /** "Invoices" → "Invoice", for the title of the screen showing one of them. */
    private function singular(string $title): string
    {
        if (str_ends_with($title, 'ies')) {
            return substr($title, 0, -3) . 'y';
        }

        return str_ends_with($title, 's') && !str_ends_with($title, 'ss')
            ? substr($title, 0, -1)
            : $title;
    }

    private function defaultSort(): string
    {
        foreach ($this->columns as $column) {
            if ($column->isKey()) {
                return "'id', 'desc'";
            }
        }

        $first = $this->columns[0]->name ?? 'id';

        return "'{$first}'";
    }

    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $this->reportSecrets($io);
        $io->note(sprintf(
            'Two steps left: add %s::class to AppServiceProvider::MODULES in sidebar order, '
            . 'and mirror the table in tests/Support/TestSchema.php or no test can see it. '
            . 'Then: php bin/console admin:check && php bin/console admin:routes',
            $class,
        ));
    }
}
