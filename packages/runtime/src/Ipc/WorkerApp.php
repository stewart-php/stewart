<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Stewart\Contracts\App\AppId;

final readonly class WorkerApp
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public AppId $id,
        public string $class,
        public array $options,
    ) {}
}
