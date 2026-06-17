<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controllers\Controller;
use App\View\PhpView;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** Exposes the protected abort() helper so it can be exercised directly. */
final class AbortingController extends Controller
{
    public function run(int $status, string $message = ''): void
    {
        $this->abort($status, $message);
    }
}

final class ControllerTest extends TestCase
{
    private function controller(): AbortingController
    {
        $psr17 = new Psr17Factory;
        return new AbortingController(new Responder($psr17, $psr17), new PhpView(sys_get_temp_dir()));
    }

    public function testAbortThrowsHttpExceptionWithGivenStatus(): void
    {
        try {
            $this->controller()->run(403);
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testAbortCarriesMessage(): void
    {
        try {
            $this->controller()->run(422, 'name is required');
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame('name is required', $e->getMessage());
        }
    }
}
