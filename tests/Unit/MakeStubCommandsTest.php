<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CommandRun;
use App\Tests\Support\Console;
use Hydra\Console\ExitCode;
use App\Console\Commands\MakeAbilityCommand;
use App\Console\Commands\MakeControllerCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The class-emitting stub generators (make:controller, make:ability) over a temp
 * directory, covering name normalisation and suffix, the rendered body, the
 * overwrite guard, and the per-generator policy (controller prints a register
 * reminder; ability denies by default and forces no suffix).
 */
#[CoversClass(MakeAbilityCommand::class)]
#[CoversClass(MakeControllerCommand::class)]
final class MakeStubCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-stub-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param array<string, string|bool> $input */
    private function controller(array $input): CommandRun
    {
        return Console::run(new MakeControllerCommand($this->dir), $input);
    }

    /** @param array<string, string|bool> $input */
    private function ability(array $input): CommandRun
    {
        return Console::run(new MakeAbilityCommand($this->dir), $input);
    }

    private function body(string $class): string
    {
        return file_get_contents($this->dir . '/' . $class . '.php');
    }

    public function test_controller_appends_suffix_and_derives_route(): void
    {
        $run = $this->controller(['name' => 'post']);
        $this->assertSame(ExitCode::Success, $run->code);

        $body = $this->body('PostController');
        $this->assertStringContainsString('final class PostController extends Controller', $body);
        $this->assertStringContainsString("#[Route('/post')]", $body);
        $this->assertStringContainsString("return \$this->render('post');", $body);
        $this->assertStringContainsString('namespace App\\Controllers;', $body);
    }

    public function test_controller_does_not_double_suffix(): void
    {
        $this->assertSame(ExitCode::Success, $this->controller(['name' => 'PostController'])->code);
        $this->assertFileExists($this->dir . '/PostController.php');
        $this->assertFileDoesNotExist($this->dir . '/PostControllerController.php');
    }

    public function test_controller_normalises_loose_names(): void
    {
        $this->assertSame(ExitCode::Success, $this->controller(['name' => 'blog-post'])->code);
        $this->assertStringContainsString('final class BlogPostController', $this->body('BlogPostController'));
    }

    public function test_controller_prints_register_reminder(): void
    {
        $run = $this->controller(['name' => 'post']);
        $this->assertStringContainsString('CONTROLLERS', $run->display());
    }

    public function test_controller_refuses_to_overwrite_without_force(): void
    {
        file_put_contents($this->dir . '/PostController.php', '<?php // hand-written');

        $run = $this->controller(['name' => 'post']);
        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('already exists', $run->display());
        // The existing file is untouched.
        $this->assertStringContainsString('hand-written', $this->body('PostController'));
    }

    public function test_controller_overwrites_with_force(): void
    {
        file_put_contents($this->dir . '/PostController.php', '<?php // hand-written');

        $this->assertSame(ExitCode::Success, $this->controller(['name' => 'post', '--force' => true])->code);
        $this->assertStringContainsString('extends Controller', $this->body('PostController'));
    }

    public function test_ability_denies_by_default_and_forces_no_suffix(): void
    {
        $run = $this->ability(['name' => 'ManagePost']);
        $this->assertSame(ExitCode::Success, $run->code);

        $body = $this->body('ManagePost');
        $this->assertStringContainsString('final class ManagePost implements AbilityInterface', $body);
        $this->assertStringContainsString('return false;', $body);
        $this->assertStringContainsString('namespace App\\Authorization;', $body);
        // No register reminder for abilities: referenced by ::class at the use site.
        $this->assertStringNotContainsString('CONTROLLERS', $run->display());
    }

    public function test_rejects_an_empty_name(): void
    {
        $run = $this->controller(['name' => '!!!']);
        $this->assertSame(ExitCode::Invalid, $run->code);
        $this->assertSame([], glob($this->dir . '/*.php') ?: []);
    }
}
