<?php

declare(strict_types=1);

namespace App\Http;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts nyholm's ServerRequestCreator to Hydra's request-provider seam,
 * keeping the choice of PSR-7 implementation here in the application.
 */
final class NyholmRequestProvider implements ServerRequestProviderInterface
{
    public function __construct(private readonly ServerRequestCreator $creator) {}

    public function fromGlobals(): ServerRequestInterface
    {
        return $this->creator->fromGlobals();
    }
}
