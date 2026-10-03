<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Selector\SelectorKind;

enum SubscriptionKind: string
{
    case StateChange = 'state_change';
    case Event = 'event';
    case Topic = 'topic';
    case Connection = 'connection';
    case Mqtt = 'mqtt';

    public function acceptsSelector(Selector $selector): bool
    {
        $isMqttFilter = $selector->getKind() === SelectorKind::MqttFilter;

        return match ($this) {
            self::Mqtt => $isMqttFilter,
            self::StateChange, self::Event, self::Topic, self::Connection => !$isMqttFilter,
        };
    }

    public function isBrokerRouted(): bool
    {
        return match ($this) {
            self::Event, self::Topic, self::Mqtt => true,
            self::StateChange, self::Connection => false,
        };
    }
}
