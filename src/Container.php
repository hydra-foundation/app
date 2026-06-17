<?php

declare(strict_types=1);

namespace App;

use DI\Container as PhpDiContainer;
use Hydra\Core\Contracts\ContainerInterface;

final class Container implements ContainerInterface
{
    public function __construct(private readonly PhpDiContainer $container) {}

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        // PHP-DI caches every resolved entry, so a set() binding is inherently
        // shared — there is no separate "singleton" call to make.
        $this->container->set($abstract, is_string($concrete)
            ? \DI\autowire($concrete)
            : \DI\factory($concrete));
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->container->set($abstract, $instance);
    }

    public function bound(string $abstract): bool
    {
        return $this->container->has($abstract);
    }
}
