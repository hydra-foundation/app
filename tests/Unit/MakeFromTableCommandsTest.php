<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CommandRun;
use App\Tests\Support\Console;
use Hydra\Console\Command;
use Hydra\Console\ExitCode;
use App\Console\Commands\MakeEntityCommand;
use App\Console\Commands\MakeFromTableCommand;
use App\Console\Commands\MakeListenerCommand;
use App\Console\Commands\MakeModuleCommand;
use App\Console\Commands\MakeRepositoryCommand;
use App\Console\Commands\MakeSourceCommand;
use App\Console\Commands\MakeSourceTestCommand;
use App\Console\Support\Column;
use App\Console\Support\TableColumns;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
#[CoversClass(MakeSourceTestCommand::class)]
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
        $run = Console::run(new MakeSourceCommand($this->dir), ['name' => 'invoice', '--columns' => 'id', '--table' => 'billing_docs']);

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
        $run = Console::run(new MakeSourceCommand($this->dir), ['name' => 'invoice', '--columns' => self::COLUMNS]);

        $this->assertStringContainsString('password_hash', $run->display());
    }

    public function test_a_writable_source_takes_the_write_contracts_and_not_the_key(): void
    {
        $run = Console::run(new MakeSourceCommand($this->dir), ['name' => 'invoice', '--columns' => self::COLUMNS, '--writable' => true]);

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

    public function test_a_module_reads_the_source_it_is_told_to(): void
    {
        $run = Console::run(new MakeModuleCommand($this->dir), ['name' => 'addresses', '--columns' => 'id', '--source' => 'AddressSource']);

        $this->assertStringContainsString('->source(AddressSource::class)', $this->body('AddressesModule'));
    }

    public function test_a_source_test_searches_the_column_the_source_searches(): void
    {
        $source = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');
        $test = $this->generate(new MakeSourceTestCommand($this->dir), 'invoice', 'InvoiceSourceTest');

        $this->assertStringContainsString("searchable: ['invoice_number', 'status', 'created_at'],", $source);
        $this->assertStringContainsString("return 'grace';", $test);
        $this->assertStringContainsString("['grace', 'value two', 'hashed-secret', '2026-01-02 09:00:00'],", $test);
        $this->assertStringContainsString('INSERT INTO invoices (invoice_number, status, password_hash, created_at)', $test);
    }

    public function test_a_source_test_runs_every_filter_the_source_declares(): void
    {
        // The pair admin:check cannot close: it reconciles the module against
        // the source's declaration, and both are declarations. A value per
        // filterable column is what makes the generated test run one.
        $source = $this->generate(new MakeSourceCommand($this->dir), 'invoice', 'InvoiceSource');
        $test = $this->generate(new MakeSourceTestCommand($this->dir), 'invoice', 'InvoiceSourceTest');

        $this->assertStringContainsString("filterable: ['status'],", $source);
        $this->assertStringContainsString("return ['status' => 'value one'];", $test);
    }

    public function test_a_table_with_nothing_to_filter_leaves_filters_to_the_contract_default(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tallies (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)');

        $run = Console::run(new MakeSourceTestCommand($this->dir, new TableColumns(new PdoConnection($pdo))), ['name' => 'tally']);

        $this->assertStringNotContainsString('filterValues', $this->body('TallySourceTest'));
    }

    public function test_a_read_only_source_test_stops_at_the_row_case(): void
    {
        $test = $this->generate(new MakeSourceTestCommand($this->dir), 'invoice', 'InvoiceSourceTest');

        $this->assertStringContainsString('final class InvoiceSourceTest extends RowSourceContractTestCase', $test);
        $this->assertStringContainsString('#[CoversClass(InvoiceSource::class)]', $test);
        $this->assertStringNotContainsString('newRow', $test);
    }

    public function test_a_writable_source_test_writes_what_the_writable_source_writes(): void
    {
        foreach ([new MakeSourceCommand($this->dir), new MakeSourceTestCommand($this->dir)] as $command) {
            Console::run($command, ['name' => 'invoice', '--columns' => self::COLUMNS, '--writable' => true])->code;
        }

        $this->assertStringContainsString("private const WRITABLE = ['invoice_number', 'status'];", $this->body('InvoiceSource'));

        $test = $this->body('InvoiceSourceTest');
        $this->assertStringContainsString('extends WritableSourceContractTestCase', $test);
        $this->assertStringContainsString("return ['invoice_number' => 'linus', 'status' => 'value six'];", $test);
        $this->assertStringContainsString("return ['invoice_number' => 'margaret', 'status' => 'value seven'];", $test);
    }

    public function test_a_table_with_nothing_to_search_leaves_search_to_the_contract_default(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tallies (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)');

        $run = Console::run(new MakeSourceTestCommand($this->dir, new TableColumns(new PdoConnection($pdo))), ['name' => 'tally']);

        $this->assertStringNotContainsString('searchMatchingSomeRows', $this->body('TallySourceTest'));
    }

    public function test_a_source_test_prints_the_sqlite_twin_it_depends_on(): void
    {
        $run = Console::run(new MakeSourceTestCommand($this->dir), ['name' => 'invoice', '--columns' => self::COLUMNS]);

        $this->assertStringContainsString('TestSchema.php', $run->display());
        $this->assertStringContainsString('CREATE TABLE invoices (', $run->display());
        $this->assertStringContainsString('id INTEGER PRIMARY KEY AUTOINCREMENT', $run->display());
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
        $run = Console::run(new MakeListenerCommand($this->dir), ['name' => 'AuditWrites', '--event' => 'Hydra\Admin\Events\RowUpdated']);

        $body = $this->body('AuditWritesListener');

        $this->assertStringContainsString('use Hydra\Admin\Events\RowUpdated;', $body);
        $this->assertStringContainsString('public function __invoke(RowUpdated $event): void', $body);
    }

    public function test_the_event_lands_the_same_however_the_shell_quoted_it(): void
    {
        // A backslash is the shell's escape character as well as PHP's namespace
        // separator, so both spellings arrive and both have to work.
        $run = Console::run(new MakeListenerCommand($this->dir), [
            'name' => 'AuditWrites',
            '--event' => 'Hydra\\\\Admin\\\\Events\\\\RowUpdated',
        ]);

        $this->assertStringContainsString('use Hydra\Admin\Events\RowUpdated;', $this->body('AuditWritesListener'));
    }

    public function test_a_listener_defaults_to_the_event_every_admin_write_raises(): void
    {
        $run = Console::run(new MakeListenerCommand($this->dir), ['name' => 'AuditWrites']);

        $this->assertStringContainsString('use Hydra\Admin\Events\AdminEvent;', $this->body('AuditWritesListener'));
        // The registration is the half a generator cannot write and the half
        // with the trap in it, so it is printed rather than summarised.
        $this->assertStringContainsString('closure', $run->display());
    }

    public function test_without_a_database_or_a_column_list_it_says_which_is_missing(): void
    {
        $run = Console::run(new MakeSourceCommand($this->dir), ['name' => 'invoice']);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('No database connection', $run->display());
        $this->assertStringContainsString('--columns', $run->display());
        $this->assertSame([], glob($this->dir . '/*.php') ?: []);
    }

    private function generate(Command $command, string $name, string $class): string
    {
        $run = Console::run($command, ['name' => $name, '--columns' => self::COLUMNS]);

        $this->assertSame(ExitCode::Success, $run->code, $run->display());

        return $this->body($class);
    }

    private function body(string $class): string
    {
        return (string) file_get_contents($this->dir . '/' . $class . '.php');
    }
}
