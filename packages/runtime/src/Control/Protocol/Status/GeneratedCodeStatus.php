<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;

final readonly class GeneratedCodeStatus
{
    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function __construct(
        public int $format,
        public int $expectedFormat,
        #[ListOf('string')]
        public array $added,
        #[ListOf('string')]
        public array $removed,
        public Instant $checkedAt,
    ) {}

    public function hasCurrentFormat(): bool
    {
        return $this->format === $this->expectedFormat;
    }

    public function hasDrift(): bool
    {
        return $this->added !== [] || $this->removed !== [];
    }

    public function hasSameDriftAs(self $other): bool
    {
        return $this->added === $other->added && $this->removed === $other->removed;
    }
}
