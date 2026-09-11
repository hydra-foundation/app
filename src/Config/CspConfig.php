<?php

declare(strict_types=1);

namespace App\Config;

use Hydra\Core\Environment;

/**
 * Content Security Policy settings
 */
final readonly class CspConfig
{
    public function __construct(
        public bool $enabled,
        public bool $reportOnly,
        public string $reportUri,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            enabled: $env->bool('CSP_ENABLED', true),
            // Report-only is for rolling the policy out over a site that
            // already has content: violations are reported and nothing is
            // blocked, so a directive that is too tight shows up before it
            // breaks a page. Enforce once the reports go quiet.
            reportOnly: $env->bool('CSP_REPORT_ONLY', false),
            reportUri: $env->string('CSP_REPORT_URI', ''),
        );
    }
}
