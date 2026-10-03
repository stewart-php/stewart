<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

enum SubscriptionKind: string
{
    case StateChange = 'state_change';
    case Event = 'event';
    case Topic = 'topic';
    case Connection = 'connection';

    public function isBrokerRouted(): bool
    {
        return match ($this) {
            self::Event, self::Topic => true,
            self::StateChange, self::Connection => false,
        };
    }
}
