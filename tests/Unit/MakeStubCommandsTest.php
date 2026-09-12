<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\MakeAbilityCommand;
use App\Console\Commands\MakeControllerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The class-emitting stub generators (make:controller, make:ability) over a temp
 * directory, covering name normalisation and suffix, the rendered body, the
 * overwrite guard, and the per-generator policy (controller prints a register
 * reminder; ability denies by default and forces no suffix).
 */
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

    private function controller(): CommandTester
    {
        return new CommandTester(new MakeControllerCommand($this->dir));
    }

    private function ability(): CommandTester
    {
        return new CommandTester(new MakeAbilityCommand($this->dir));
    }

    private function body(string $class): string
    {
        return file_get_contents($this->dir . '/' . $class . '.php');
    }

    public function test_controller_appends_suffix_and_derives_route(): void
    {
        $tester = $this->controller();
        $this->assertSame(Command::SUCCESS, $tester->execute(['name' => 'post']));

        $body = $this->body('PostController');
        $this->assertStringContainsString('final class PostController extends Controller', $body);
        $this->assertStringContainsString("#[Route('/post')]", $body);
        $this->assertStringContainsString("return \$this->render('post');", $body);
        $this->assertStringContainsString('namespace App\\Controllers;', $body);
    }

    public function test_controller_does_not_double_suffix(): void
    {
        $this->assertSame(Command::SUCCESS, $this->controller()->execute(['name' => 'PostController']));
        $this->assertFileExists($this->dir . '/PostController.php');
        $this->assertFileDoesNotExist($this->dir . '/PostControllerController.php');
    }

    public function test_controller_normalises_loose_names(): void
    {
        $this->assertSame(Command::SUCCESS, $this->controller()->execute(['name' => 'blog-post']));
        $this->assertStringContainsString('final class BlogPostController', $this->body('BlogPostController'));
    }

    public function test_controller_prints_register_reminder(): void
    {
        $tester = $this->controller();
        $tester->execute(['name' => 'post']);
        $this->assertStringContainsString('CONTROLLERS', $tester->getDisplay());
    }

    public function test_controller_refuses_to_overwrite_without_force(): void
    {
        file_put_contents($this->dir . '/PostController.php', '<?php // hand-written');

        $tester = $this->controller();
        $this->assertSame(Command::FAILURE, $tester->execute(['name' => 'post']));
        $this->assertStringContainsString('already exists', $tester->getDisplay());
        // The existing file is untouched.
        $this->assertStringContainsString('hand-written', $this->body('PostController'));
    }

    public function test_controller_overwrites_with_force(): void
    {
        file_put_contents($this->dir . '/PostController.php', '<?php // hand-written');

        $this->assertSame(Command::SUCCESS, $this->controller()->execute(['name' => 'post', '--force' => true]));
        $this->assertStringContainsString('extends Controller', $this->body('PostController'));
    }

    public function test_ability_denies_by_default_and_forces_no_suffix(): void
    {
        $tester = $this->ability();
        $this->assertSame(Command::SUCCESS, $tester->execute(['name' => 'ManagePost']));

        $body = $this->body('ManagePost');
        $this->assertStringContainsString('final class ManagePost implements AbilityInterface', $body);
        $this->assertStringContainsString('return false;', $body);
        $this->assertStringContainsString('namespace App\\Authorization;', $body);
        // No register reminder for abilities: referenced by ::class at the use site.
        $this->assertStringNotContainsString('CONTROLLERS', $tester->getDisplay());
    }

    public function test_rejects_an_empty_name(): void
    {
        $tester = $this->controller();
        $this->assertSame(Command::FAILURE, $tester->execute(['name' => '!!!']));
        $this->assertSame([], glob($this->dir . '/*.php') ?: []);
    }
}
