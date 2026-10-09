<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

final readonly class ComponentEventDecoder
{
    private const string SESSION_REPLACED = 'session_replaced';

    /** @param array<string, mixed> $event */
    public function decodeSessionEvent(array $event): ?ComponentSessionEvent
    {
        return match ($event['type'] ?? null) {
            self::SESSION_REPLACED => new SessionReplaced(),
            default => null,
        };
    }
}
