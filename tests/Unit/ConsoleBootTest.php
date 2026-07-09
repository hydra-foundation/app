<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Bootstrap;
use Hydra\Auth\Events\Attempting;
use Hydra\Event\ListenerProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The console must boot the providers, not just register them.
 *
 * Regression: bin/console used to build the composition root and resolve
 * bindings without ever calling Application::boot(), so everything wired in a
 * provider's boot() — the auth audit listeners, for one — silently did not
 * exist for commands. The HTTP path never had the bug because
 * Application::run() boots.
 */
final class ConsoleBootTest extends TestCase
{
    /**
     * Booting the REAL composition root (the same Bootstrap::application() +
     * boot() sequence bin/console performs) wires the cross-provider pieces:
     * after boot(), the shared ListenerProvider knows the auth audit listeners
     * that AppServiceProvider::boot() attaches.
     *
     * Runs in its own process: building the REAL root parses the app's .env,
     * and Environment exports those values to $_ENV/putenv — isolation keeps
     * that export from leaking into the other tests' environment handling.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_booting_the_real_composition_root_wires_the_audit_listeners(): void
    {
        $app = Bootstrap::application(dirname(__DIR__, 2));
        $app->boot();

        $listeners = $app->container()->get(ListenerProvider::class);
        $matched = iterator_to_array($listeners->getListenersForEvent(new Attempting('probe')), false);

        $this->assertNotEmpty(
            $matched,
            'boot() must attach the auth audit listeners — a booted console sees the same wired world a request does.',
        );
    }

    /**
     * bin/console is a procedural script, so the only way to lock in that it
     * actually performs the boot() call (and does so before resolving anything)
     * is to read its source. Deliberately blunt: if the call disappears, this
     * fails with a message saying exactly what regressed.
     */
    public function test_console_entrypoint_calls_boot(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/bin/console');
        $this->assertIsString($source);

        $boot = strpos($source, '->boot();');
        $this->assertNotFalse($boot, 'bin/console must call boot() on the Application — commands rely on booted providers.');

        // boot() must run before the first container resolution, otherwise
        // early-resolved services see a half-wired world.
        $firstGet = strpos($source, '$container->get(');
        if ($firstGet !== false) {
            $this->assertLessThan($firstGet, $boot, 'bin/console must boot before resolving from the container.');
        }
    }
}
