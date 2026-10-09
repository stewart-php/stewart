<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

final readonly class ComponentCommandAnswer
{
    private function __construct(
        public string $commandId,
        public bool $accepted,
        public ?string $message,
    ) {}

    public static function accept(string $commandId): self
    {
        return new self($commandId, true, null);
    }

    public static function reject(string $commandId, string $message): self
    {
        return new self($commandId, false, $message);
    }
}
