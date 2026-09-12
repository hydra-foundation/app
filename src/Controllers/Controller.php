<?php

declare(strict_types=1);

namespace App\Controllers;

use Hydra\Kernel\Controller as KernelController;

/**
 * The app-owned extension point for helpers every controller in this project
 * should have. The response and view helpers stay in {@see KernelController} so
 * they cannot drift between Hydra apps.
 */
abstract class Controller extends KernelController {}
