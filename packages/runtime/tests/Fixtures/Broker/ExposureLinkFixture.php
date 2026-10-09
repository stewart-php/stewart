<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

final class ExposureLinkFixture
{
    public static function createWithoutExposures(): ExposureLink
    {
        $timers = new ManualTimers();
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $timers,
            new NullLogger(),
            FakeWebsocketConnector::createStalled(),
        );
        $client = new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());

        return new ExposureLink($client, new NullLogger(), new ComponentTracker($timers->clock), new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));
    }
}
