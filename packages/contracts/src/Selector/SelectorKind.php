<?php

declare(strict_types=1);

namespace Stewart\Contracts\Selector;

enum SelectorKind: string
{
    case Exact = 'exact';
    case Glob = 'glob';
    case Regex = 'regex';
    case AnyOf = 'any_of';
    case MqttFilter = 'mqtt_filter';
}
