<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentCommand;
use Stewart\Client\Component\ComponentCommandAction;
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
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\Exposure\ExposedEntitySync;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\LiveExposure;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
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

    /** @var list<ExposedEntityCommand> */
    private array $commanded = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->client = $this->createClient();
        $this->tracker = new ComponentTracker($this->timers->clock);
        $this->link = new ExposureLink($this->client, new NullLogger(), $this->tracker, new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));
        $this->link->onEntitySynced(function (ExposedEntitySync $sync): void {
            $this->synced[] = $sync;
        });
        $this->link->onEntityCommanded(function (ExposedEntityCommand $command): void {
            $this->commanded[] = $command;
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

    public function testExposureIsSentAvailable(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();

        $this->exposeTemperature(21.4);

        self::assertTrue($this->socket->listSentOfType('stewart/entity/upsert')[0]['available'] ?? null);
    }

    public function testOrphanedAppTurnsUnavailable(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();
        $this->exposeTemperature(21.4);
        $this->socket->replyWhenSent('stewart/entity/state', ['type' => 'result', 'success' => true, 'result' => null]);

        $this->link->orphanExposuresOfApp(new AppId('climate'));
        EventLoopTicks::settle();

        self::assertFalse($this->socket->listSentOfType('stewart/entity/state')[0]['available'] ?? null);
    }

    public function testOrphanIsReplayedUnavailableWithoutSync(): void
    {
        $this->exposeTemperature(21.4);
        $this->link->orphanExposuresOfApp(new AppId('climate'));
        $this->activateComponent();
        $this->replyToUpsert();

        $this->link->replayAll();

        self::assertFalse($this->socket->listSentOfType('stewart/entity/upsert')[0]['available'] ?? null);
        self::assertSame([], $this->socket->listSentOfType('stewart/entity/state'));
        self::assertSame([], $this->synced);
    }

    public function testExposingOrphanAgainMakesItAvailable(): void
    {
        $this->exposeTemperature(21.4);
        $this->link->orphanExposuresOfApp(new AppId('climate'));
        $this->exposeTemperature(22.0);
        $this->activateComponent();
        $this->replyToUpsert();

        $this->link->replayAll();

        self::assertTrue($this->socket->listSentOfType('stewart/entity/upsert')[0]['available'] ?? null);
        self::assertCount(1, $this->synced);
    }

    public function testLostWorkerOrphansOnlyItsEntities(): void
    {
        $this->activateComponent();
        $this->replyToUpsert();
        $this->exposeTemperature(21.4);
        $this->replyToUpsert();
        $this->link->exposeEntity(
            new WorkerId(1),
            new AppId('lights'),
            new ExposedEntityKey('night_mode'),
            ExposedEntityDefinition::fromConfig(new SensorConfig(), null),
            new ExposedStateChange(new ExposedState('on')),
        );
        $this->socket->replyWhenSent('stewart/entity/state', ['type' => 'result', 'success' => true, 'result' => null]);

        $this->link->orphanExposuresOfWorker(new WorkerId(0));
        EventLoopTicks::settle();

        $states = $this->socket->listSentOfType('stewart/entity/state');
        self::assertCount(1, $states);
        self::assertSame('climate', $states[0]['app'] ?? null);
    }

    public function testCountIncludesOrphans(): void
    {
        $this->exposeTemperature(21.4);
        $this->link->orphanExposuresOfApp(new AppId('climate'));

        self::assertSame(1, $this->link->countExposuresOfApp(new AppId('climate')));
        self::assertSame(0, $this->link->countExposuresOfApp(new AppId('lights')));
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

    public function testCommandForUnknownEntityIsRejected(): void
    {
        $this->socket->replyWhenSent('stewart/command/result', ['type' => 'result', 'success' => true, 'result' => null]);

        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::TurnOn));
        EventLoopTicks::settle();

        $answer = $this->socket->listSentOfType('stewart/command/result')[0] ?? [];
        self::assertFalse($answer['ok'] ?? null);
        self::assertSame('Entity night_mode of app lights is not exposed by a running app.', $answer['message'] ?? null);
        self::assertSame([], $this->commanded);
    }

    public function testCommandForOrphanHasNoOwner(): void
    {
        $this->exposeNightMode(false);
        $this->link->orphanExposuresOfApp(new AppId('lights'));

        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::TurnOn));

        self::assertCount(1, $this->commanded);
        self::assertNull($this->commanded[0]->owner);
    }

    public function testCommandReachesOwningWorker(): void
    {
        $this->exposeNightMode(false);

        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::TurnOff));

        self::assertCount(1, $this->commanded);
        self::assertTrue($this->commanded[0]->owner?->equals(new WorkerId(1)));
        self::assertInstanceOf(SwitchCommand::class, $this->commanded[0]->command);
        self::assertFalse($this->commanded[0]->command->isTurnOn());
        self::assertSame('user-1', $this->commanded[0]->command->getContext()->userId);
    }

    public function testPressBecomesButtonPress(): void
    {
        $this->exposeNightMode(null);

        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::Press));

        self::assertInstanceOf(ButtonPress::class, $this->commanded[0]->command ?? null);
    }

    public function testAcceptedCommandStateIsReplayed(): void
    {
        $this->exposeNightMode(false);
        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::TurnOn));
        $this->socket->replyWhenSent('stewart/command/result', ['type' => 'result', 'success' => true, 'result' => null]);

        $this->link->acceptCommand($this->commanded[0]);
        EventLoopTicks::settle();
        $this->activateComponent();
        $this->replyToUpsert();
        $this->link->replayAll();

        self::assertTrue($this->socket->listSentOfType('stewart/command/result')[0]['ok'] ?? null);
        self::assertTrue($this->socket->listSentOfType('stewart/entity/upsert')[0]['state'] ?? null);
    }

    public function testRejectedCommandKeepsState(): void
    {
        $this->exposeNightMode(false);
        $this->link->receiveCommand(self::createComponentCommand(ComponentCommandAction::TurnOn));
        $this->socket->replyWhenSent('stewart/command/result', ['type' => 'result', 'success' => true, 'result' => null]);

        $this->link->rejectCommand($this->commanded[0], 'Alarm is armed.');
        EventLoopTicks::settle();
        $this->activateComponent();
        $this->replyToUpsert();
        $this->link->replayAll();

        self::assertSame('Alarm is armed.', $this->socket->listSentOfType('stewart/command/result')[0]['message'] ?? null);
        self::assertFalse($this->socket->listSentOfType('stewart/entity/upsert')[0]['state'] ?? null);
    }

    private function exposeNightMode(?bool $state): void
    {
        $this->link->exposeEntity(
            new WorkerId(1),
            new AppId('lights'),
            new ExposedEntityKey('night_mode'),
            ExposedEntityDefinition::fromConfig(new SwitchConfig(), null),
            new ExposedStateChange($state === null ? null : new ExposedState($state)),
        );
    }

    private static function createComponentCommand(ComponentCommandAction $action): ComponentCommand
    {
        return new ComponentCommand('3f2b9c0e8d7a4f61', new AppId('lights'), new ExposedEntityKey('night_mode'), $action, [], new EventContext('context-1', null, 'user-1'));
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
