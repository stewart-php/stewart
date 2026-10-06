<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\State\EventContext;

final readonly class RecordedEventFire
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $eventType,
        public array $data,
        public EventContext $context,
    ) {}
}
