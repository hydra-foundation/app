<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;

/** What a command did, and what it said while doing it. */
final readonly class CommandRun
{
    public function __construct(
        public ExitCode $code,
        public FakeOutput $output,
    ) {}

    /** Everything said, as one string, for a contains-assertion. */
    public function display(): string
    {
        return implode("\n", $this->output->lines());
    }
}
