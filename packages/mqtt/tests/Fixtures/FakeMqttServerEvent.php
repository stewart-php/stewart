<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Fixtures;

enum FakeMqttServerEvent
{
    case ConnectReceived;
    case Subscribed;
    case Published;
    case Disconnected;
}
