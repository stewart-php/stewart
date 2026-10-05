<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger;

use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Instant;

final readonly class TriggerEvent
{
    /** @param array<string, mixed> $trigger */
    public function __construct(
        public array $trigger,
        public ?EventContext $context = null,
        public ?Instant $firedAt = null,
    ) {}

    public function getPlatform(): ?string
    {
        $platform = $this->trigger['platform'] ?? $this->trigger['trigger'] ?? null;

        return \is_string($platform) ? $platform : null;
    }

    public function getTriggerId(): ?string
    {
        $id = $this->trigger['id'] ?? null;

        if (\is_string($id)) {
            return $id;
        }

        $index = $this->trigger['idx'] ?? null;

        return \is_string($index) || \is_int($index) ? (string) $index : null;
    }

    /** @return array<string, mixed> */
    public function getTriggerData(): array
    {
        return $this->trigger;
    }

    public function getValue(string $key): mixed
    {
        return $this->trigger[$key] ?? null;
    }

    public function withFiredAt(Instant $firedAt): self
    {
        return new self($this->trigger, $this->context, $firedAt);
    }
}
