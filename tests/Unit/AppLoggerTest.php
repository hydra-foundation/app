<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\LogConfig;
use App\Providers\AppServiceProvider;
use Hydra\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Where the app's own log lines go: the file the admin reads, and stderr for the container. */
#[CoversClass(AppServiceProvider::class)]
final class AppLoggerTest extends TestCase
{
    private string $file;
    private string $stderr;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/hydra-applogger-' . bin2hex(random_bytes(6));
        $this->file = $base . '.log';
        $this->stderr = $base . '.stderr';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->stderr);
    }

    public function test_a_record_goes_to_the_file_and_to_stderr_with_its_request_id(): void
    {
        $requestId = new RequestId;
        $requestId->set('abc123');

        AppServiceProvider::logger(new LogConfig($this->file), $requestId, $this->stderr)->warning('careful');

        $this->assertStringContainsString('WARNING: careful {"request_id":"abc123"}', (string) file_get_contents($this->file));
        $this->assertStringContainsString('WARNING: careful', (string) file_get_contents($this->stderr));
    }

    public function test_without_stderr_only_the_file_is_written(): void
    {
        AppServiceProvider::logger(new LogConfig($this->file, stderr: false), new RequestId, $this->stderr)->info('quiet');

        $this->assertStringContainsString('INFO: quiet', (string) file_get_contents($this->file));
        $this->assertFileDoesNotExist($this->stderr);
    }

    public function test_a_file_that_cannot_be_opened_falls_back_to_stderr(): void
    {
        AppServiceProvider::logger(new LogConfig('/nonexistent/dir/x.log', stderr: false), new RequestId, $this->stderr)->error('lost?');

        $this->assertStringContainsString('ERROR: lost?', (string) file_get_contents($this->stderr));
    }

    public function test_secrets_are_still_redacted(): void
    {
        AppServiceProvider::logger(new LogConfig($this->file, stderr: false), new RequestId, $this->stderr)->info('x', ['password' => 'hunter2']);

        $this->assertStringNotContainsString('hunter2', (string) file_get_contents($this->file));
    }
}
