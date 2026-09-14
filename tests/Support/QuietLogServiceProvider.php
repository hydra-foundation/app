<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\ServiceProviderInterface;
use Hydra\Log\StreamLogger;
use Psr\Log\LoggerInterface;

/**
 * A logger that writes into memory instead of the process's stderr.
 *
 * The flow tests drive real requests through the real pipeline, and the real
 * pipeline logs one line per request. Those lines land in the middle of
 * PHPUnit's own output, where they read as failures that are not failures, and
 * `beStrictAboutOutputDuringTests` cannot catch them because a logger writing
 * to a stream never passes through output buffering at all.
 *
 * Registered after AppServiceProvider, since it is that binding it replaces.
 */
final class QuietLogServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(LoggerInterface::class, function (): LoggerInterface {
            return new StreamLogger(fopen('php://memory', 'a+') ?: fopen('php://temp', 'a+'));
        });
    }

    public function boot(ContainerInterface $container): void {}
}
