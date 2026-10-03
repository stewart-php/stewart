<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final readonly class ManifestDrift
{
    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {}

    /**
     * @param list<string> $generated
     * @param list<string> $live
     * @param list<string> $ignored
     */
    public static function calculateBetween(array $generated, array $live, array $ignored = []): self
    {
        $known = array_flip([...$generated, ...$ignored]);
        $present = array_flip($live);

        $added = array_values(array_filter($live, static fn(string $id): bool => !isset($known[$id])));
        $removed = array_values(array_filter($generated, static fn(string $id): bool => !isset($present[$id])));

        sort($added);
        sort($removed);

        return new self($added, $removed);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [];
    }
}
