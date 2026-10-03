<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;

final readonly class Everything
{
    /**
     * @param list<Label> $labels
     * @param list<int> $numbers
     * @param array<array-key, mixed> $tags
     * @param array<array-key, mixed>|scalar|null $payload
     */
    public function __construct(
        public string $name,
        public int $count,
        public float $ratio,
        public bool $enabled,
        public ?string $note,
        public Color $color,
        public ?Label $label,
        #[ListOf(Label::class)]
        public array $labels,
        #[ListOf('int')]
        public array $numbers,
        public array $tags,
        public bool|int|float|string|array|null $payload,
        public Instant $seenAt,
        public ?Duration $lag,
    ) {}
}
