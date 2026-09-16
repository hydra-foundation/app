<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\MakeEntityCommand;
use App\Console\Commands\MakeFromTableCommand;
use App\Console\Commands\MakeListenerCommand;
use App\Console\Commands\MakeModuleCommand;
use App\Console\Commands\MakeRepositoryCommand;
use App\Console\Commands\MakeSourceCommand;
use App\Console\Support\Column;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generators that write code shaped like a table, driven with an explicit
 * --columns list so no database is needed.
 *
 * What is worth asserting here is not that a file appeared. It is that the four
 * of them agree — a module's fields against its source's lists, a repository's
 * hydration against its entity's constructor — because disagreement between any
 * two of those is silent at runtime, and writing them together is the only
 * reason to generate them at all.
 */
#[CoversClass(MakeFromTableCommand::class)]
#[CoversClass(MakeSourceCommand::class)]
#[CoversClass(MakeModuleCommand::class)]
#[CoversClass(MakeEntityCommand::class)]
#[CoversClass(MakeRepositoryCommand::class)]
#[CoversClass(MakeListenerCommand::class)]
#[CoversClass(Column::class)]
final class MakeFromTableCommandsTest extends TestCase
{
    private const COLUMNS = 'id,invoice_number,status,password_hash,created_at';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-table-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_a_source_is_written_as_the_declaration_it_is(): void
    {
        $body = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');

        $this->assertStringContainsString('final class InvoiceSource extends TableSource', $body);
        $this->assertStringContainsString("table: 'invoices',", $body);
        $this->assertStringContainsString("columns: ['id', 'invoice_number', 'status', 'created_at'],", $body);
        $this->assertStringContainsString("defaultSort: 'id',", $body);
    }

    public function test_the_table_is_the_plural_of_the_name_unless_one_is_given(): void
    {
        $tester = new CommandTester(new MakeSourceCommand($this->dir));
        $tester->execute(['name' => 'invoice', '--columns' => 'id', '--table' => 'billing_docs']);

        $this->assertStringContainsString("table: 'billing_docs',", $this->body('InvoiceSource'));
    }

    public function test_a_source_leaves_a_credential_column_out_of_every_list(): void
    {
        // The mistake this exists to prevent: password_hash selected into the
        // list screen, the show screen and the CSV export, with nothing
        // anywhere complaining.
        $body = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');

        $this->assertStringNotContainsString('password_hash', $body);
    }

    public function test_a_column_left_out_is_said_out_loud(): void
    {
        // A secret omitted is the one thing about the generated file that is not
        // visible in the generated file.
        $tester = new CommandTester(new MakeSourceCommand($this->dir));
        $tester->execute(['name' => 'invoice', '--columns' => self::COLUMNS]);

        $this->assertStringContainsString('password_hash', $tester->getDisplay());
    }

    public function test_a_writable_source_takes_the_write_contracts_and_not_the_key(): void
    {
        $tester = new CommandTester(new MakeSourceCommand($this->dir));
        $tester->execute(['name' => 'invoice', '--columns' => self::COLUMNS, '--writable' => true]);

        $body = $this->body('InvoiceSource');

        foreach (['CreateSourceInterface', 'UpdateSourceInterface', 'DeleteSourceInterface'] as $contract) {
            $this->assertStringContainsString($contract, $body);
        }

        // The key is the database's to issue and the timestamp is its to keep.
        $this->assertStringContainsString("private const WRITABLE = ['invoice_number', 'status'];", $body);
    }

    public function test_a_read_only_source_implements_no_write_contract_at_all(): void
    {
        // Read-only is expressed by absence, and the registry raises at boot on
        // a screen asking for a contract this does not have.
        $body = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');

        $this->assertStringNotContainsString('CreateSourceInterface', $body);
        $this->assertStringNotContainsString('public function delete(', $body);
    }

    public function test_a_module_names_only_columns_the_generated_source_reads(): void
    {
        $module = $this->generate(new MakeModuleCommand($this->dir), 'invoices', 'InvoicesModule');

        $this->assertStringContainsString("Field::text('invoice_number')", $module);
        $this->assertStringContainsString("Field::text('status')", $module);
        $this->assertStringNotContainsString('password_hash', $module);
    }

    public function test_a_module_answers_at_its_own_slug_and_reads_a_singular_source(): void
    {
        // The slug is the module's, not the table's: a second view onto one
        // table still mounts at its own path.
        $module = $this->generate(new MakeModuleCommand($this->dir), 'invoices', 'InvoicesModule');

        $this->assertStringContainsString("Definition::make('invoices')", $module);
        $this->assertStringContainsString('->source(InvoiceSource::class)', $module);
        $this->assertStringContainsString("ShowScreen::make()->title('Invoice')", $module);
    }

    public function test_a_module_filters_where_the_source_filters(): void
    {
        // status is filterable in both files or in neither; the pair is the
        // whole reason to write them together.
        $module = $this->generate(new MakeModuleCommand($this->dir), 'invoices', 'InvoicesModule');
        $source = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');

        $this->assertStringContainsString("Field::text('status')->labelled('Status')->sortable()->searchable()->filterable()", $module);
        $this->assertStringContainsString("filterable: ['status'],", $source);
    }

    public function test_an_entity_is_a_readonly_value_without_the_key(): void
    {
        // An entity is what a row says; the id is what the database calls it.
        $body = $this->generate(new MakeEntityCommand($this->dir), 'invoice', 'Invoice');

        $this->assertStringContainsString('final readonly class Invoice', $body);
        $this->assertStringContainsString('public ?string $invoiceNumber,', $body);
        $this->assertStringNotContainsString('$id,', $body);
        $this->assertStringNotContainsString('passwordHash', $body);
    }

    public function test_a_repository_reads_named_columns_rather_than_everything(): void
    {
        // SELECT * is how a hash added by a later migration arrives in every
        // caller the moment the migration runs.
        $body = $this->generate(new MakeRepositoryCommand($this->dir), 'invoice', 'InvoiceRepository');

        $this->assertStringNotContainsString('SELECT * FROM', $body);
        $this->assertStringContainsString("implode(', ', self::READS)", $body);
        $this->assertStringContainsString("private const READS = ['id', 'invoice_number', 'status', 'created_at'];", $body);
        $this->assertStringContainsString("private const COLUMNS = ['invoice_number', 'status'];", $body);
    }

    public function test_a_repository_hydrates_every_property_its_entity_requires(): void
    {
        // Hydrating only the written columns leaves a required constructor
        // argument unfilled, which is an ArgumentCountError on the first read.
        $entity = $this->generate(new MakeEntityCommand($this->dir), 'invoice', 'Invoice');
        $repository = $this->generate(new MakeRepositoryCommand($this->dir), 'invoice', 'InvoiceRepository');

        preg_match_all('/public \?\w+ \$(\w+),/', $entity, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $property) {
            $this->assertStringContainsString("{$property}: \$row[", $repository);
        }
    }

    public function test_a_listener_hears_one_event_and_imports_it_once(): void
    {
        $tester = new CommandTester(new MakeListenerCommand($this->dir));
        $tester->execute(['name' => 'AuditWrites', '--event' => 'Hydra\Admin\Events\RowUpdated']);

        $body = $this->body('AuditWritesListener');

        $this->assertStringContainsString('use Hydra\Admin\Events\RowUpdated;', $body);
        $this->assertStringContainsString('public function __invoke(RowUpdated $event): void', $body);
    }

    public function test_the_event_lands_the_same_however_the_shell_quoted_it(): void
    {
        // A backslash is the shell's escape character as well as PHP's namespace
        // separator, so both spellings arrive and both have to work.
        $tester = new CommandTester(new MakeListenerCommand($this->dir));
        $tester->execute([
            'name' => 'AuditWrites',
            '--event' => 'Hydra\\\\Admin\\\\Events\\\\RowUpdated',
        ]);

        $this->assertStringContainsString('use Hydra\Admin\Events\RowUpdated;', $this->body('AuditWritesListener'));
    }

    public function test_a_listener_defaults_to_the_event_every_admin_write_raises(): void
    {
        $tester = new CommandTester(new MakeListenerCommand($this->dir));
        $tester->execute(['name' => 'AuditWrites']);

        $this->assertStringContainsString('use Hydra\Admin\Events\AdminEvent;', $this->body('AuditWritesListener'));
        // The registration is the half a generator cannot write and the half
        // with the trap in it, so it is printed rather than summarised.
        $this->assertStringContainsString('closure', $tester->getDisplay());
    }

    public function test_without_a_database_or_a_column_list_it_says_which_is_missing(): void
    {
        $tester = new CommandTester(new MakeSourceCommand($this->dir));

        $this->assertSame(Command::FAILURE, $tester->execute(['name' => 'invoice']));
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
        $this->assertStringContainsString('--columns', $tester->getDisplay());
        $this->assertSame([], glob($this->dir . '/*.php') ?: []);
    }

    private function generate(Command $command, string $name, string $class): string
    {
        $tester = new CommandTester($command);
        $tester->execute(['name' => $name, '--columns' => self::COLUMNS]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $this->body($class);
    }

    private function body(string $class): string
    {
        return (string) file_get_contents($this->dir . '/' . $class . '.php');
    }
}
