<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

final class ExposureLinkFixture
{
    public static function createWithoutExposures(): ExposureLink
    {
        $timers = new ManualTimers();

        return self::createLink(self::createClient($timers, FakeWebsocketConnector::createStalled()), new ComponentTracker($timers->clock));
    }

    public static function createWithExposedSensor(AppId $appId, ExposedEntityKey $key, EntityId $entityId): ExposureLink
    {
        $timers = new ManualTimers();
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $socket->replyWhenSent('stewart/entity/upsert', ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => $entityId->value,
            'state' => null,
            'attributes' => [],
            'available' => true,
        ]]);
        $client = self::createClient($timers, new FakeWebsocketConnector($socket));
        $client->connect();
        $tracker = new ComponentTracker($timers->clock);
        $tracker->recordState(ComponentState::Active, null);
        $link = self::createLink($client, $tracker);
        $link->exposeEntity(new WorkerId(0), $appId, $key, ExposedEntityDefinition::fromConfig(new SensorConfig(), null), new ExposedStateChange());

        return $link;
    }

    private static function createLink(HaClient $client, ComponentTracker $tracker): ExposureLink
    {
        return new ExposureLink($client, new NullLogger(), $tracker, new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));
    }

    private static function createClient(ManualTimers $timers, FakeWebsocketConnector $connector): HaClient
    {
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
            $connector,
        );

        return new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
    }
}
