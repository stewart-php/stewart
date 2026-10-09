<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

final readonly class OpenExposedCommand
{
    public function __construct(
        public string $commandId,
        public ExposedHandle $handle,
    ) {}
}
