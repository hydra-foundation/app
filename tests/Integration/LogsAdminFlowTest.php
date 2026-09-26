<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\LogConfig;
use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use Hydra\Log\StreamLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The end of the log file, read from the admin: newest first, narrowed by
 * level, request and text, with a line's trace and context one click away.
 */
#[CoversNothing]
final class LogsAdminFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;
    private string $path;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->path = sys_get_temp_dir() . '/hydra-logs-flow-' . bin2hex(random_bytes(6)) . '.log';
        $this->app->container()->instance(LogConfig::class, new LogConfig($this->path, stderr: false));
        $this->http = $this->app->http();

        $this->write(static function (StreamLogger $log): void {
            $log->info('Mail sent to {to}', ['to' => 'ada@example.test', 'request_id' => 'req-mail']);
            $log->warning('Slow query', ['ms' => 912]);
            $log->error('Could not record activity: {why}', [
                'why' => 'disk full',
                'exception' => new RuntimeException('disk full'),
                'request_id' => 'req-boom',
            ]);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_the_latest_lines_are_listed_newest_first_under_monitoring(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/logs');

        $this->assertStringContainsString('<title>Logs · Admin</title>', $body);
        $this->assertStringContainsString('>Monitoring<', $body);
        $this->assertLessThan(strpos($body, 'Slow query'), strpos($body, 'Could not record activity: disk full'));
        $this->assertLessThan(strpos($body, 'Mail sent to ada@example.test'), strpos($body, 'Slow query'));
        $this->assertStringContainsString('>Error</td>', $body);
        $this->assertStringContainsString('<time datetime=', $body);
        $this->assertStringContainsString('Reading the last 2 MB of the log.', $body);
        $this->assertStringNotContainsString('#0 ', $body);
    }

    public function test_the_log_is_admin_only(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/logs')->assertStatus(403);
    }

    public function test_the_level_the_request_and_the_text_each_narrow_the_list(): void
    {
        $this->login('boss');

        $errors = $this->body('/admin/logs?level=error');
        $this->assertStringContainsString('Could not record activity', $errors);
        $this->assertStringNotContainsString('Slow query', $errors);

        $request = $this->body('/admin/logs?request_id=req-mail');
        $this->assertStringContainsString('Mail sent to', $request);
        $this->assertStringNotContainsString('Could not record activity', $request);

        $search = $this->body('/admin/logs?q=SLOW');
        $this->assertStringContainsString('Slow query', $search);
        $this->assertStringNotContainsString('Mail sent to', $search);
    }

    public function test_a_line_opens_on_its_trace_and_its_context(): void
    {
        $this->login('boss');
        $offset = $this->offsetOf('ERROR: Could not record activity');
        $body = $this->body('/admin/logs/' . $offset);

        $this->assertStringContainsString('<title>Log line · Admin</title>', $body);
        $this->assertMatchesRegularExpression('#<pre[^>]*>RuntimeException: disk full in \S+\n\#0 #', $body);
        $this->assertStringContainsString('&quot;why&quot;: &quot;disk full&quot;', $body);
        $this->assertStringContainsString('>req-boom<', $body);
    }

    public function test_the_first_line_of_the_file_opens_too(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('Mail sent to ada@example.test', $this->body('/admin/logs/0'));
    }

    public function test_a_line_no_longer_in_the_window_says_so(): void
    {
        $this->login('boss');

        $this->http->htmx('div#admin-frame')->get('/admin/logs/7')
            ->assertStatus(404)
            ->assertSee('That line is no longer in the part of the log this reads.');
    }

    public function test_the_log_cannot_be_written_from_here(): void
    {
        $this->login('boss');

        $this->http->post('/admin/logs/0/delete')->assertStatus(404);
        $this->http->get('/admin/logs/0/edit')->assertStatus(404);
        $this->assertStringNotContainsString('>Delete</button>', $this->body('/admin/logs'));
    }

    public function test_logs_sent_only_to_stderr_are_explained_rather_than_missing(): void
    {
        $this->app->container()->instance(LogConfig::class, new LogConfig('php://stderr'));
        $this->login('boss');
        $body = $this->body('/admin/logs');

        $this->assertStringContainsString('Logs go to stderr. Set LOG_PATH to a file to read them here.', $body);
        $this->assertStringContainsString('No results', $body);
    }

    private function offsetOf(string $needle): int
    {
        $at = strpos((string) file_get_contents($this->path), $needle);
        $this->assertNotFalse($at);

        return (int) strrpos(substr((string) file_get_contents($this->path), 0, $at), "\n") + 1;
    }

    /** @param callable(StreamLogger): void $writes */
    private function write(callable $writes): void
    {
        $stream = fopen($this->path, 'a');
        $writes(new StreamLogger($stream));
        fclose($stream);
    }

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
