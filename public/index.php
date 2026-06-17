<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;

// The HTTP front controller: build the shared composition root and run the
// HTTP kernel. The console (bin/console) builds the same root a different way.
Bootstrap::application(__DIR__ . '/..')->run();
