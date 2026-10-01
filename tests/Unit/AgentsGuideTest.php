<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * AGENTS.md is read by agents as instructions, and an agent runs what it is
 * told without wondering whether a command was renamed. So what it names is
 * checked against the skeleton as it is:
 *
 * - `./hydra <name>` and `bin/console <name>` must be registered commands,
 *   asked of the real console (a name has a colon: make:job);
 * - `composer <name>` must be a script in composer.json, or one of Composer's
 *   own commands the file uses;
 * - a backticked path under a top-level directory, or a backticked root file,
 *   must exist.
 *
 * Links into the wiki are not checked here: they live in another repository.
 */
#[CoversNothing]
final class AgentsGuideTest extends TestCase
{
    /** Composer's own commands, which composer.json does not list. */
    private const COMPOSER_BUILTINS = ['install', 'update', 'require'];

    private static string $root;
    private static string $guide;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        self::$guide = (string) file_get_contents(self::$root . '/AGENTS.md');
    }

    public function test_claude_code_reads_the_same_file(): void
    {
        $this->assertSame("@AGENTS.md\n", file_get_contents(self::$root . '/CLAUDE.md'));
    }

    public function test_every_console_command_it_names_is_registered(): void
    {
        // A name has a colon (make:job), which keeps prose such as "./hydra is
        // bin/console inside the container" from reading as a command.
        preg_match_all('#(?:\./hydra|bin/console)\s+([a-z][a-z0-9-]*:[a-z0-9:-]+)#', self::$guide, $matches);
        $named = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($named, 'AGENTS.md names no console command; the pattern above has drifted from the file.');
        $this->assertSame([], array_values(array_diff($named, $this->registeredCommands())), 'AGENTS.md names commands bin/console does not have.');
    }

    public function test_every_composer_command_it_names_exists(): void
    {
        preg_match_all('#composer\s+([a-z][a-z0-9:-]*)#', self::$guide, $matches);
        $composer = json_decode((string) file_get_contents(self::$root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $known = [...array_keys($composer['scripts'] ?? []), ...self::COMPOSER_BUILTINS];

        $this->assertContains('qa', $matches[1]);
        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), $known)), 'AGENTS.md names composer commands that do not exist.');
    }

    public function test_every_path_it_names_exists(): void
    {
        preg_match_all('#`((?:src|tests|bin|database|docker|public|views|storage)/[^`\s]*|\.env\.example|composer\.json|phpunit\.xml)`#', self::$guide, $matches);
        $missing = array_filter(
            array_unique($matches[1]),
            static fn (string $path): bool => !file_exists(self::$root . '/' . rtrim($path, '/')),
        );

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], array_values($missing), 'AGENTS.md names paths that do not exist.');
    }

    /** @return list<string> */
    private function registeredCommands(): array
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$root . '/bin/console') . ' list 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        preg_match_all('/^\s{2}([a-z][a-z0-9:-]*)\s{2,}/m', implode("\n", $output), $matches);

        return $matches[1];
    }
}
