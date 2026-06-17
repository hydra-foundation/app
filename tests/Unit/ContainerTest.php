<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Container;
use DI\Container as PhpDiContainer;
use Hydra\Core\Contracts\ContainerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

interface Animal {}
final class Dog implements Animal {}

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container(new PhpDiContainer);
    }

    public function testSingletonMapsInterfaceToConcreteInstance(): void
    {
        // Regression: the old set($id, $concrete) stored the literal class
        // string, so get() returned "Dog" instead of a Dog instance.
        $this->container->singleton(Animal::class, Dog::class);

        $resolved = $this->container->get(Animal::class);

        $this->assertInstanceOf(Dog::class, $resolved);
    }

    public function testSingletonWithClosureDoesNotThrow(): void
    {
        // Regression: the old singleton() passed the closure to \DI\autowire(),
        // which only accepts a class-name string and threw a TypeError.
        $this->container->singleton('service', fn() => new stdClass);

        $this->assertInstanceOf(stdClass::class, $this->container->get('service'));
    }

    public function testResolvedEntriesAreShared(): void
    {
        // PHP-DI caches resolved entries, so repeat resolution returns the same
        // instance — singleton() is the only binding semantics there is.
        $this->container->singleton('service', fn() => new stdClass);

        $this->assertSame(
            $this->container->get('service'),
            $this->container->get('service')
        );
    }

    public function testInstanceRegistersExactObject(): void
    {
        $object = new stdClass;
        $this->container->instance('the-object', $object);

        $this->assertSame($object, $this->container->get('the-object'));
    }

    public function testBoundAndHasReflectRegistration(): void
    {
        $this->assertFalse($this->container->bound('missing'));
        $this->assertFalse($this->container->has('missing'));

        $this->container->instance('present', new stdClass);

        $this->assertTrue($this->container->bound('present'));
        $this->assertTrue($this->container->has('present'));
    }

    public function testImplementsHydraContainerContract(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }
}
