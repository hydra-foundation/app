<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Bootstrap;
use App\Tests\Support\TestApp;
use Hydra\Cache\CacheServiceProvider;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Security\SignerServiceProvider;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Mail\Testing\FakeMailServiceProvider;
use Hydra\Session\SessionServiceProvider;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversNothing]
final class TestAppTest extends TestCase
{
    /** Each double and the provider whose slot it takes; null adds to the stack. */
    private const DOUBLES = [
        ArraySessionServiceProvider::class => SessionServiceProvider::class,
        FixedSignerServiceProvider::class => SignerServiceProvider::class,
        ArrayCacheServiceProvider::class => CacheServiceProvider::class,
        FakeMailServiceProvider::class => null,
    ];

    public function test_the_harness_boots_the_providers_bootstrap_does_in_the_same_order(): void
    {
        // A provider added to Bootstrap and not here leaves every flow test green
        // against an application that no longer exists.
        $this->assertSame(
            $this->providers(Bootstrap::application(sys_get_temp_dir())),
            array_values(array_filter(array_map(
                static fn (string $class): ?string => array_key_exists($class, self::DOUBLES) ? self::DOUBLES[$class] : $class,
                $this->providers(TestApp::boot()->application()),
            ))),
        );
    }

    /** @return list<class-string> */
    private function providers(Application $application): array
    {
        return array_map(
            static fn (object $provider): string => $provider::class,
            (new ReflectionProperty($application, 'providers'))->getValue($application),
        );
    }
}
