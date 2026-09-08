<?php

/**
 *  ▄▄             ▄▄
 *  ██             ██
 *  ████▄ ██ ██ ▄████ ████▄  ▀▀█▄
 *  ██ ██ ██▄██ ██ ██ ██ ▀▀ ▄█▀██
 *  ██ ██  ▀██▀ ▀████ ██    ▀█▄██
 *          ██
 *        ▀▀▀
 *   Welcome to Hydra!
 *   Please read the wiki: https://hydrakit.dev/
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

App\Bootstrap::application(__DIR__ . '/..')->run();
