<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Console\AdminCheckCommand;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\ModuleRegistry;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\PhpDi\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every module in `AppServiceProvider::MODULES` against the source it reads.
 *
 * This is `admin:check` run over the real module list, and it is here because
 * the command is not in `composer qa` and cannot be: it needs a booted
 * application and a database, and CI has neither at the point the suite runs.
 * What the command catches is silent — a `->filterable()` field the source does
 * not list builds no clause at all, so the screen answers a narrowed request
 * with the whole table and says nothing — so leaving it to be run by hand would
 * leave it not run.
 *
 * It replaces the per-module pairing assertions that used to sit in each source
 * test. Those checked one column each, by hand, and only for the modules
 * somebody remembered to write one for; this covers every field of every
 * module, including the ones added after it was written.
 */
#[CoversNothing]
final class AdminModulePairingTest extends TestCase
{
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ModuleRegistry($this->container(), AppServiceProvider::MODULES);
    }

    public function test_every_module_names_only_columns_its_source_offers(): void
    {
        $tester = new CommandTester(new AdminCheckCommand($this->registry));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function test_every_module_that_declares_a_source_is_one_the_check_can_read(): void
    {
        // The check reports a source it cannot build as *unchecked*, which is
        // the right answer for a join or a remote service and the wrong one for
        // a source this harness simply failed to construct. Resolving each one
        // here turns that silence into a failure: without it, a module whose
        // source gained a dependency would go on passing the case above while
        // being checked against nothing at all.
        foreach ($this->registry->all() as $blueprint) {
            if ($blueprint->source === null) {
                continue;
            }

            $this->assertInstanceOf(
                DescribesColumnsInterface::class,
                $this->registry->source($blueprint),
                sprintf('The "%s" module\'s source cannot describe its columns.', $blueprint->slug),
            );
        }
    }

    private function container(): ContainerInterface
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(ConnectionInterface::class, new PdoConnection(TestSchema::connect()));
        $container->instance(HasherInterface::class, $this->hasher());
        $container->instance(GuardInterface::class, $this->guard());

        return $container;
    }

    /** Nothing here hashes or authorizes; the sources only have to be built. */
    private function hasher(): HasherInterface
    {
        return new class implements HasherInterface {
            public function hash(string $plain): string
            {
                return 'hashed:' . $plain;
            }

            public function verify(string $plain, string $hash): bool
            {
                return $hash === 'hashed:' . $plain;
            }

            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
    }

    private function guard(): GuardInterface
    {
        return new class implements GuardInterface {
            public function user(): ?AuthenticatableInterface
            {
                return null;
            }

            public function check(): bool
            {
                return true;
            }

            public function id(): int
            {
                return 99;
            }

            public function attempt(string $username, string $password): bool
            {
                return false;
            }

            public function login(AuthenticatableInterface $user): void {}

            public function logout(): void {}
        };
    }
}
