<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class UnsubscribeEvents implements HaCommand
{
    public function __construct(public int $subscriptionId) {}

    public function type(): string
    {
        return 'unsubscribe_events';
    }

    public function describe(): string
    {
        return \sprintf('unsubscribe_events %d', $this->subscriptionId);
    }

    public function toMessage(): array
    {
        return ['type' => $this->type(), 'subscription' => $this->subscriptionId];
    }
}
