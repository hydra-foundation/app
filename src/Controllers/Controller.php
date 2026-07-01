<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Kernel\Controller as KernelController;

/**
 * The app's base controller. The response/view helpers (render, abort) live in
 * {@see KernelController} so they don't drift between Hydra apps; this thin
 * subclass is the app-owned extension point — add project-wide controller
 * helpers here and every controller inherits them.
 */
abstract class Controller extends KernelController
{
}
