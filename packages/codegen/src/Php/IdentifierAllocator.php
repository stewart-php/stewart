<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

use LogicException;

final class IdentifierAllocator
{
    /** @var array<string, true> */
    private array $taken = [];

    /** @param list<string> $reserved */
    public function __construct(
        private readonly array $reserved,
        private readonly string $suffix,
    ) {}

    public function claim(string $preferred): string
    {
        $base = $this->isReserved($preferred) ? $preferred . $this->suffix : $preferred;
        $candidate = $base;
        $attempt = 1;

        while ($this->isTaken($candidate)) {
            $candidate = $base . ++$attempt;
        }

        $this->taken[strtolower($candidate)] = true;

        return $candidate;
    }

    public function claimExactly(string $name): void
    {
        if ($this->isReserved($name) || $this->isTaken($name)) {
            throw new LogicException(\sprintf('%s is not free to claim.', $name));
        }

        $this->taken[strtolower($name)] = true;
    }

    public function isReserved(string $name): bool
    {
        return \in_array(strtolower($name), array_map(strtolower(...), $this->reserved), true);
    }

    private function isTaken(string $name): bool
    {
        return isset($this->taken[strtolower($name)]);
    }
}
