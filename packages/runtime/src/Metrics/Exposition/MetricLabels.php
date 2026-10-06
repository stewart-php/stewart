<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

final readonly class MetricLabels
{
    /** @param array<non-empty-string, string> $valuesByName */
    private function __construct(private array $valuesByName) {}

    public static function none(): self
    {
        return new self([]);
    }

    /** @param non-empty-string $name */
    public static function fromLabel(string $name, string $value): self
    {
        return new self([$name => $value]);
    }

    /** @param non-empty-string $name */
    public function withLabel(string $name, string $value): self
    {
        return new self([...$this->valuesByName, $name => $value]);
    }

    /** @return array<non-empty-string, string> */
    public function listValuesByName(): array
    {
        return $this->valuesByName;
    }
}
