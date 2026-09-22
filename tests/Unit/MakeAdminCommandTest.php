<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CommandRun;
use App\Tests\Support\Console;
use Hydra\Console\ExitCode;
use App\Console\Commands\MakeAdminCommand;
use App\Console\Commands\MakeEntityCommand;
use App\Console\Commands\MakeModuleCommand;
use App\Console\Commands\MakeRepositoryCommand;
use App\Console\Commands\MakeSourceCommand;
use App\Console\Commands\MakeSourceTestCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MakeAdminCommand::class)]
final class MakeAdminCommandTest extends TestCase
{
    private const COLUMNS = 'id,street,city,created_at';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-admin-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->dir);
    }

    public function test_it_writes_the_source_the_module_and_the_source_test(): void
    {
        $run = $this->generate(['name' => 'address']);

        $this->assertSame(ExitCode::Success, $run->code, $run->display());
        $this->assertSame(
            ['modules/AddressesModule.php', 'sources/AddressSource.php', 'tests/AddressSourceTest.php'],
            $this->written(),
        );
    }

    public function test_the_module_reads_the_source_it_wrote_beside_it(): void
    {
        // make:module's own guess at "Addresses" is "AddresseSource".
        $this->generate(['name' => 'addresses']);

        $this->assertStringContainsString('->source(AddressSource::class)', $this->body('modules/AddressesModule.php'));
    }

    public function test_every_file_reads_the_same_table(): void
    {
        $this->generate(['name' => 'blog post']);

        $this->assertStringContainsString("table: 'blog_posts',", $this->body('sources/BlogPostSource.php'));
        $this->assertStringContainsString('INSERT INTO blog_posts ', $this->body('tests/BlogPostSourceTest.php'));
        $this->assertStringContainsString("Definition::make('blog_posts')", $this->body('modules/BlogPostsModule.php'));
    }

    public function test_a_given_table_is_passed_to_every_generator(): void
    {
        $this->generate(['name' => 'address', '--table' => 'postal']);

        $this->assertStringContainsString("table: 'postal',", $this->body('sources/AddressSource.php'));
        $this->assertStringContainsString('INSERT INTO postal ', $this->body('tests/AddressSourceTest.php'));
    }

    public function test_writable_reaches_the_source_and_its_test(): void
    {
        $this->generate(['name' => 'address', '--writable' => true]);

        $this->assertStringContainsString('CreateSourceInterface', $this->body('sources/AddressSource.php'));
        $this->assertStringContainsString('extends WritableSourceContractTestCase', $this->body('tests/AddressSourceTest.php'));
    }

    public function test_the_entity_and_repository_are_written_only_when_asked_for(): void
    {
        $this->generate(['name' => 'address', '--repository' => true]);

        $this->assertContains('entities/Address.php', $this->written());
        $this->assertContains('repositories/AddressRepository.php', $this->written());
    }

    public function test_nothing_is_written_when_any_file_already_exists(): void
    {
        mkdir($this->dir . '/tests');
        file_put_contents($this->dir . '/tests/AddressSourceTest.php', 'hand-written');

        $run = $this->generate(['name' => 'address']);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('AddressSourceTest.php', $run->display());
        $this->assertSame(['tests/AddressSourceTest.php'], $this->written());
        $this->assertSame('hand-written', $this->body('tests/AddressSourceTest.php'));
    }

    public function test_force_overwrites_them(): void
    {
        mkdir($this->dir . '/tests');
        file_put_contents($this->dir . '/tests/AddressSourceTest.php', 'hand-written');

        $run = $this->generate(['name' => 'address', '--force' => true]);

        $this->assertSame(ExitCode::Success, $run->code);
        $this->assertStringContainsString('final class AddressSourceTest', $this->body('tests/AddressSourceTest.php'));
    }

    public function test_without_a_database_or_a_column_list_nothing_is_written(): void
    {
        $run = Console::run($this->command(), ['name' => 'address']);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('No database connection', $run->display());
        $this->assertSame([], $this->written());
    }

    /** @param array<string, mixed> $input */
    private function generate(array $input): CommandRun
    {
        $run = Console::run($this->command(), [...$input, '--columns' => self::COLUMNS]);

        return $run;
    }

    private function command(): MakeAdminCommand
    {
        return new MakeAdminCommand(
            new MakeSourceCommand($this->dir . '/sources'),
            new MakeModuleCommand($this->dir . '/modules'),
            new MakeSourceTestCommand($this->dir . '/tests'),
            new MakeEntityCommand($this->dir . '/entities'),
            new MakeRepositoryCommand($this->dir . '/repositories'),
        );
    }

    /** @return list<string> */
    private function written(): array
    {
        $files = array_map(
            fn (string $path): string => substr($path, strlen($this->dir) + 1),
            glob($this->dir . '/*/*.php') ?: [],
        );
        sort($files);

        return $files;
    }

    private function body(string $path): string
    {
        return (string) file_get_contents($this->dir . '/' . $path);
    }
}
