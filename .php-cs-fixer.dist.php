<?php

declare(strict_types=1);

/**
 * The same ruleset the framework packages are held to, so a skeleton that
 * drifts from the code it is a sample of fails the build rather than teaching
 * the drift to every project started from it.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/database',
        __DIR__ . '/public',
        __DIR__ . '/views',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,

        // Hydra writes `new AuthConfig`, not `new AuthConfig()`.
        'new_with_parentheses' => false,

        // ... and keeps an empty body on one line: `public function x(): void {}`.
        'single_line_empty_body' => true,
    ])
    ->setFinder($finder);
