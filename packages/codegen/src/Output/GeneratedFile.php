<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}

    public static function forClass(string $shortName, string $contents): self
    {
        return new self($shortName . '.php', $contents);
    }
}
