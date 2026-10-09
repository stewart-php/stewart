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
    case Trigger = 'trigger';
    case ExposedCommand = 'exposed_command';

    public function acceptsSelector(Selector $selector): bool
    {
        $isMqttFilter = $selector->getKind() === SelectorKind::MqttFilter;

        return match ($this) {
            self::Mqtt => $isMqttFilter,
            self::Trigger, self::ExposedCommand => $selector->getKind() === SelectorKind::Exact,
            self::StateChange, self::Event, self::Topic, self::Connection => !$isMqttFilter,
        };
    }

    public function isBrokerRouted(): bool
    {
        return match ($this) {
            self::Event, self::Topic, self::Mqtt, self::Trigger => true,
            self::StateChange, self::Connection, self::ExposedCommand => false,
        };
    }

    public function needsTriggerSpec(): bool
    {
        return match ($this) {
            self::Trigger => true,
            self::StateChange, self::Event, self::Topic, self::Connection, self::Mqtt, self::ExposedCommand => false,
        };
    }
}
