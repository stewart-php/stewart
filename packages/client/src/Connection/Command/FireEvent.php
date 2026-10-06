<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Event\EventPayload;

final readonly class FireEvent implements HaCommand
{
    public function __construct(public EventPayload $payload) {}

    public function type(): string
    {
        return 'fire_event';
    }

    public function describe(): string
    {
        return \sprintf('fire_event %s', $this->payload->eventType);
    }

    public function toMessage(): array
    {
        $message = ['type' => $this->type(), 'event_type' => $this->payload->eventType];

        if ($this->payload->data !== []) {
            $message['event_data'] = $this->payload->data;
        }

        return $message;
    }
}
