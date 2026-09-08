<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Kernel\Controller as KernelController;
use Hydra\Http\{Htmx, HtmxResponse};
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Application base controller
 */
abstract class Controller extends KernelController
{
    /**
     * Redirect after a state change
     */
    public function redirectTo(Request $request, string $to): Response
    {
        if (Htmx::fromRequest($request)->isHtmx()) {
            return (new HtmxResponse)->redirect($to)->applyTo($this->respond->noContent());
        }

        return $this->respond->redirect($to);
    }
}
