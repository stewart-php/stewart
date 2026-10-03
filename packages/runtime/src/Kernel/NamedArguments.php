<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Symfony\Component\DependencyInjection\Argument\BoundArgument;

final readonly class NamedArguments
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = []) {}

    public function withArgument(string $name, mixed $value): self
    {
        return new self([...$this->values, '$' . $name => $value]);
    }

    // Untracked: a profile may leave one unused, e.g. projectDir without the codegen package.
    /** @return array<string, BoundArgument> */
    public function toBoundArguments(): array
    {
        return array_map(
            static fn(mixed $value): BoundArgument => new BoundArgument($value, false),
            $this->values,
        );
    }
}
