<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class SubscribeEvents implements HaCommand
{
    public function __construct(public ?string $eventType = null) {}

    public function type(): string
    {
        return 'subscribe_events';
    }

    public function describe(): string
    {
        return \sprintf('subscribe_events "%s"', $this->eventType ?? '*');
    }

    public function toMessage(): array
    {
        $message = ['type' => $this->type()];

        if ($this->eventType !== null) {
            $message['event_type'] = $this->eventType;
        }

        return $message;
    }
}
