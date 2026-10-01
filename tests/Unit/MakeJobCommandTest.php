<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\MakeJobCommand;
use App\Tests\Support\Console;
use Hydra\Console\ExitCode;
use Hydra\Queue\Contracts\JobInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * make:job against a scratch directory: the file it writes has to be a job the
 * worker can run, not only text that looks like one, so it is loaded and asked.
 */
#[CoversClass(MakeJobCommand::class)]
final class MakeJobCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-job-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_a_job_is_a_class_the_worker_can_run(): void
    {
        $run = Console::run(new MakeJobCommand($this->dir), ['name' => 'SendWelcomeMail']);

        $this->assertSame(ExitCode::Success, $run->code, $run->display());

        $file = $this->dir . '/SendWelcomeMail.php';
        token_get_all((string) file_get_contents($file), TOKEN_PARSE);
        require $file;

        $this->assertTrue(is_subclass_of('App\Jobs\SendWelcomeMail', JobInterface::class));
        $this->assertStringContainsString('public function handle(array $payload): void', (string) file_get_contents($file));
    }

    public function test_a_job_is_named_as_given_with_no_suffix_forced_on_it(): void
    {
        // Jobs are verbs (SendVerificationLink), so "send invoice" stays one.
        Console::run(new MakeJobCommand($this->dir), ['name' => 'send invoice']);

        $this->assertFileExists($this->dir . '/SendInvoice.php');
        $this->assertSame([$this->dir . '/SendInvoice.php'], glob($this->dir . '/*.php'));
    }

    public function test_an_existing_job_is_not_overwritten(): void
    {
        file_put_contents($this->dir . '/SendInvoice.php', 'MINE');

        $run = Console::run(new MakeJobCommand($this->dir), ['name' => 'SendInvoice']);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertSame('MINE', file_get_contents($this->dir . '/SendInvoice.php'));
    }

    public function test_it_says_how_to_push_it_and_what_a_payload_carries(): void
    {
        $run = Console::run(new MakeJobCommand($this->dir), ['name' => 'SendInvoice']);

        $this->assertStringContainsString('->push(SendInvoice::class, [', $run->display());
        $this->assertStringContainsString('ids', $run->display());
    }
}
