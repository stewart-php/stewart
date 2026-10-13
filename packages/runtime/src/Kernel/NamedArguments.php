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

    // Tracked, so compiling fails when no service takes a bound name, e.g. after a parameter rename.
    /** @return array<string, BoundArgument> */
    public function toBoundArguments(): array
    {
        return array_map(
            static fn(mixed $value): BoundArgument => new BoundArgument($value, true),
            $this->values,
        );
    }
}
