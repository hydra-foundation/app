<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Core\Security\Signer;
use Hydra\Csrf\Honeypot;
use Hydra\Csrf\Rules\SubmittedAfter;
use Hydra\View\Contracts\ViewInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The form traps as the app wires them: built by hand (autowiring would skip
 * the limits), signed under the app's key, and reachable from any view.
 */
#[CoversNothing]
final class HoneypotWiringTest extends TestCase
{
    private TestApp $app;

    private ?string $template = null;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    protected function tearDown(): void
    {
        if ($this->template !== null) {
            @unlink($this->template);
        }
    }

    public function test_the_start_time_is_signed_with_the_apps_key(): void
    {
        preg_match('/name="_started" value="([^"]+)"/', $this->app->get(Honeypot::class)->markup(), $match);

        $message = $this->app->get(Signer::class)->verify(html_entity_decode($match[1] ?? ''));

        $this->assertNotNull($message);
        $this->assertStringStartsWith(SubmittedAfter::PREFIX, $message);
    }

    public function test_a_view_prints_the_traps(): void
    {
        $name = 'honeypot-wiring-' . bin2hex(random_bytes(4));
        $this->template = dirname(__DIR__, 2) . "/views/{$name}.php";
        file_put_contents($this->template, '<?= $this->honeypot() ?>');

        $out = $this->app->get(ViewInterface::class)->render($name, layout: false);

        $this->assertStringContainsString('class="form-trap"', $out);
        $this->assertStringContainsString('name="' . Honeypot::STARTED . '"', $out);
    }
}
