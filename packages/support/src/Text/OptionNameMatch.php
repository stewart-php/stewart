<?php

declare(strict_types=1);

namespace Stewart\Support\Text;

/** @internal */
final readonly class OptionNameMatch
{
    /** @param list<string> $candidates */
    private function __construct(
        public ?string $parameter,
        public array $candidates,
        public ?string $suggestion,
    ) {}

    public static function matched(string $parameter): self
    {
        return new self($parameter, [$parameter], null);
    }

    /** @param list<string> $candidates */
    public static function ambiguous(array $candidates): self
    {
        return new self(null, $candidates, null);
    }

    public static function unmatched(?string $suggestion): self
    {
        return new self(null, [], $suggestion);
    }

    public function isAmbiguous(): bool
    {
        return \count($this->candidates) > 1;
    }
}
