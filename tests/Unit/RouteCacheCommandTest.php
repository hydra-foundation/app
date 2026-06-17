<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\RouteCacheCommand;
use App\Console\Commands\RouteCacheClearCommand;
use Hydra\Http\Attributes\Route;
use Hydra\Http\RouteCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** A controller the scanner can reflect — never instantiated, only its attributes read. */
final class CacheableRoutesController
{
    #[Route('/posts')]
    public function index(): void {}

    #[Route('/posts', methods: ['POST'])]
    public function store(): void {}
}

final class RouteCacheCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hydra-routecachecmd-' . uniqid('', true) . '/routes.php';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    public function testCompilesTheControllerRoutesToTheCache(): void
    {
        $cache = new RouteCache($this->path);
        $tester = new CommandTester(
            new RouteCacheCommand($cache, [CacheableRoutesController::class]),
        );

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Cached 2 route(s)', $tester->getDisplay());

        // The artifact is loadable and holds exactly the scanned routes.
        $routes = $cache->load();
        $this->assertCount(2, $routes);
        $this->assertSame('/posts', $routes[0]['path']);
    }

    public function testClearDeletesTheCache(): void
    {
        $cache = new RouteCache($this->path);
        $cache->store([['method' => 'GET', 'path' => '/x', 'handler' => ['X', 'y'], 'middleware' => []]]);

        $tester = new CommandTester(new RouteCacheClearCommand($cache));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('cleared', $tester->getDisplay());
        $this->assertNull($cache->load());
    }

    public function testClearIsASuccessfulNoOpWhenCacheIsCold(): void
    {
        $tester = new CommandTester(new RouteCacheClearCommand(new RouteCache($this->path)));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('already clear', $tester->getDisplay());
    }
}
