<?php

declare(strict_types=1);

namespace Stewart\Codegen;

final readonly class GenerationTarget
{
    public function __construct(
        public string $namespace,
        public string $directory,
    ) {}

    public function classFor(string $shortName): string
    {
        return $this->namespace . '\\' . $shortName;
    }
}
