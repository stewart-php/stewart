<?php

declare(strict_types=1);

namespace Stewart\Contracts\Event;

use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Instant;

final readonly class HaEvent
{
    public const string STATE_CHANGED = 'state_changed';

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $type,
        public array $data = [],
        public EventOrigin $origin = EventOrigin::Local,
        public ?Instant $firedAt = null,
        public ?EventContext $context = null,
    ) {}

    public function getValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
