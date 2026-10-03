<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Runtime\Ipc\WorkerApp;

final readonly class AppRegistration
{
    /** @param array<string, mixed> $optionArguments */
    public function __construct(
        public WorkerApp $app,
        public array $optionArguments,
    ) {}
}
