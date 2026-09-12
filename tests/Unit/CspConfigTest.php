<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\CspConfig;
use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CSP switches to a policy that is enforced, not merely reported, when
 * nothing is configured. The two ways of weakening the policy have to be asked
 * for, so a missing or misspelt key cannot quietly stop enforcing it.
 */
final class CspConfigTest extends TestCase
{
    private const KEYS = ['CSP_ENABLED', 'CSP_REPORT_ONLY', 'CSP_REPORT_URI'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-cspconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV; scrub so values don't leak.
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): CspConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return CspConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_exposes_readonly_settings_from_constructor(): void
    {
        $config = new CspConfig(enabled: false, reportOnly: true, reportUri: '/csp');

        $this->assertFalse($config->enabled);
        $this->assertTrue($config->reportOnly);
        $this->assertSame('/csp', $config->reportUri);
    }

    public function test_sends_an_enforced_policy_with_no_reporting_by_default(): void
    {
        $config = $this->fromEnv("APP_NAME=x\n");

        $this->assertTrue($config->enabled);
        $this->assertFalse($config->reportOnly);
        $this->assertSame('', $config->reportUri);
    }

    public function test_can_be_turned_off_entirely(): void
    {
        $this->assertFalse($this->fromEnv("CSP_ENABLED=false\n")->enabled);
    }

    public function test_can_be_put_in_report_only_mode(): void
    {
        $this->assertTrue($this->fromEnv("CSP_REPORT_ONLY=true\n")->reportOnly);
    }

    public function test_reads_the_report_endpoint(): void
    {
        $this->assertSame('/csp-report', $this->fromEnv("CSP_REPORT_URI=/csp-report\n")->reportUri);
    }
}
