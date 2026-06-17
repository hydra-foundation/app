<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\KeyGenerateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class KeyGenerateCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        $this->envPath = sys_get_temp_dir() . '/hydra-keygen-' . uniqid('', true) . '.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new KeyGenerateCommand($this->envPath));
    }

    private function key(): string
    {
        preg_match('/^APP_KEY=(.*)$/m', file_get_contents($this->envPath), $m);
        return $m[1] ?? '';
    }

    public function testWritesAKeyWhenAppKeyIsEmpty(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Hydra\nAPP_KEY=\n");

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        // A 256-bit key is 64 hex chars; the rest of the file is preserved.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
        $this->assertStringContainsString('APP_NAME=Hydra', file_get_contents($this->envPath));
    }

    public function testRefusesToOverwriteAnExistingKeyWithoutForce(): void
    {
        file_put_contents($this->envPath, "APP_KEY=existing\n");

        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('already set', $tester->getDisplay());

        // The existing key is left untouched.
        $this->assertSame('existing', $this->key());
    }

    public function testOverwritesAnExistingKeyWithForce(): void
    {
        file_put_contents($this->envPath, "APP_KEY=existing\n");

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['--force' => true]));
        $this->assertNotSame('existing', $this->key());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
    }

    public function testAppendsAppKeyWhenTheLineIsAbsent(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Hydra\n");

        $this->assertSame(Command::SUCCESS, $this->tester()->execute([]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
        $this->assertStringContainsString('APP_NAME=Hydra', file_get_contents($this->envPath));
    }

    public function testFailsWhenEnvFileIsMissing(): void
    {
        $tester = $this->tester(); // setUp() never created the file

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No .env', $tester->getDisplay());
    }
}
