<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposedEntitySync;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\LiveExposure;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

#[CoversClass(ExposureLink::class)]
#[CoversClass(LiveExposure::class)]
final class ExposureLinkTest extends TestCase
{
    use AssertsReason;

    private const string ENTITY_ID = 'sensor.stewart_climate_average_temperature';

    private ManualTimers $timers;

    private FakeWebsocketConnection $socket;

    private HaClient $client;

    private ComponentTracker $tracker;

    private ExposureLink $link;

    /** @var list<ExposedEntitySync> */
    private array $synced = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->client = $this->createClient();
        $this->tracker = new ComponentTracker($this->timers->clock);
        $this->link = new ExposureLink($this->client, new NullLogger(), $this->tracker, new ExposeConfig(ComponentInstance::parse('default')));
        $this->link->onEntitySynced(function (ExposedEntitySync $sync): void {
            $this->synced[] = $sync;
        });
        $this->client->connect();
    }

    public function testExposureIsPendingBeforeDetection(): void
    {
        self::assertNull($this->exposeTemperature(21.4));
        self::assertSame([], $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    public function testMissingComponentRefusesExposure(): void
    {
        $this->tracker->recordState(ComponentState::Missing, null);

        $this->assertThrowsReason(ExposureError::ComponentMissing, fn() => $this->exposeTemperature(21.4));
    }

    public function testActiveComponentAnswersWithSnapshot(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();

        self::assertSame(self::ENTITY_ID, $this->exposeTemperature(21.4)?->entityId->value);
        self::assertSame(21.4, $this->socket->listSentOfType('stewart/entity/upsert')[0]['state'] ?? null);
    }

    public function testRejectedConfigIsNotReplayed(): void
    {
        $this->activateComponent();
        $this->socket->replyWhenSent('stewart/entity/upsert', self::createRejection('invalid_config'));

        $this->assertThrowsReason(ExposureError::ConfigInvalid, fn() => $this->exposeTemperature(21.4));
        $this->link->replayAll();

        self::assertCount(1, $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    public function testLostSessionLeavesExposurePending(): void
    {
        $this->activateComponent();
        $this->socket->replyWhenSent('stewart/entity/upsert', self::createRejection('no_session'));

        self::assertNull($this->exposeTemperature(21.4));
    }

    public function testReplaySendsLatestStateAndSyncsOwner(): void
    {
        $this->exposeTemperature(21.4);
        $this->link->updateEntity(new AppId('climate'), new ExposedEntityKey('average_temperature'), new ExposedStateChange(new ExposedState(22.0)));
        $this->activateComponent();
        $this->replyToUpsert();

        $this->link->replayAll();

        self::assertSame(22.0, $this->socket->listSentOfType('stewart/entity/upsert')[0]['state'] ?? null);
        self::assertCount(1, $this->synced);
        self::assertSame(self::ENTITY_ID, $this->synced[0]->snapshot->entityId->value);
    }

    public function testForgottenAppIsNotReplayed(): void
    {
        $this->exposeTemperature(21.4);
        $this->link->forgetExposuresOfApp(new AppId('climate'));
        $this->activateComponent();

        $this->link->replayAll();

        self::assertSame([], $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    public function testDeletedEntityIsUpsertedAgain(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();
        $this->exposeTemperature(21.4);
        $this->socket->replyWhenSent('stewart/entity/state', self::createRejection('not_found'));
        $this->replyToUpsert();

        $this->link->updateEntity(new AppId('climate'), new ExposedEntityKey('average_temperature'), new ExposedStateChange(new ExposedState(23.5)));

        self::assertSame(23.5, $this->socket->listSentOfType('stewart/entity/upsert')[1]['state'] ?? null);
        self::assertCount(1, $this->synced);
    }

    public function testRejectedStateIsThrown(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();
        $this->exposeTemperature(21.4);
        $this->socket->replyWhenSent('stewart/entity/state', self::createRejection('invalid_state'));

        $this->assertThrowsReason(
            ExposureError::StateInvalid,
            fn() => $this->link->updateEntity(new AppId('climate'), new ExposedEntityKey('average_temperature'), new ExposedStateChange(new ExposedState('warm'))),
        );
    }

    public function testUpdateOfUnknownEntityIsRemoved(): void
    {
        $this->assertThrowsReason(
            ExposureError::Removed,
            fn() => $this->link->updateEntity(new AppId('climate'), new ExposedEntityKey('average_temperature'), new ExposedStateChange()),
        );
    }

    public function testRemovedEntityLeavesReplay(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();
        $this->socket->replyWhenSent('stewart/entity/remove', ['type' => 'result', 'success' => true, 'result' => ['removed' => true]]);
        $this->exposeTemperature(21.4);

        $this->link->removeEntity(new AppId('climate'), new ExposedEntityKey('average_temperature'));
        $this->link->replayAll();

        self::assertCount(1, $this->socket->listSentOfType('stewart/entity/remove'));
        self::assertCount(1, $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    private function exposeTemperature(float $state): ?ExposedEntitySnapshot
    {
        return $this->link->exposeEntity(
            new WorkerId(0),
            new AppId('climate'),
            new ExposedEntityKey('average_temperature'),
            ExposedEntityDefinition::fromConfig(new SensorConfig(unit: '°C'), null),
            new ExposedStateChange(new ExposedState($state)),
        );
    }

    private function activateComponent(): void
    {
        $this->tracker->recordState(ComponentState::Active, null);
    }

    private function replyToUpsert(): void
    {
        $this->socket->replyWhenSent('stewart/entity/upsert', ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => self::ENTITY_ID,
            'state' => 21.4,
            'attributes' => [],
            'available' => true,
        ]]);
    }

    /** @return array<string, mixed> */
    private static function createRejection(string $code): array
    {
        return ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => 'Refused.']];
    }

    private function createClient(): HaClient
    {
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $this->timers,
            new NullLogger(),
            new FakeWebsocketConnector($this->socket),
        );

        return new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
    }
}
