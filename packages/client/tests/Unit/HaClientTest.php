<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ComponentSessionEvent;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Component\SessionReplaced;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

use function Amp\async;

#[CoversClass(HaClient::class)]
final class HaClientTest extends TestCase
{
    use AssertsReason;

    public function testSiteSettingsCarryTimeZoneAndLocation(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => [
            'time_zone' => 'Europe/Budapest',
            'latitude' => 47.4979,
            'longitude' => 19.0402,
            'elevation' => 96,
        ]]);
        $settings = $client->getSiteSettings();

        self::assertSame('Europe/Budapest', $settings->timeZone->getName());
        self::assertSame(47.4979, $settings->location?->latitude);
        self::assertCount(1, $socket->listSentOfType('get_config'));
    }

    public function testUnusableTimeZoneFallsBackToUtc(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => ['time_zone' => 'Mars/Olympus']]);
        $settings = $client->getSiteSettings();

        self::assertSame('UTC', $settings->timeZone->getName());
        self::assertNull($settings->location);
    }

    public function testSubscribingToEverythingNamesNoEventType(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $client->subscribeAllEvents(static function (): void {}, static function (): void {});

        $subscribes = $socket->listSentOfType('subscribe_events');

        self::assertCount(1, $subscribes);
        self::assertArrayNotHasKey('event_type', $subscribes[0]);
    }

    public function testAllEventsSplitsOffStateChanges(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $changes = [];
        $events = [];

        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $id = $client->subscribeAllEvents(
            static function (StateChange $change) use (&$changes): void {
                $changes[] = $change->entityId->value;
            },
            static function (HaEvent $event) use (&$events): void {
                $events[] = $event->type;
            },
        );

        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => [
            'event_type' => 'state_changed',
            'data' => ['entity_id' => 'light.hall', 'new_state' => ['entity_id' => 'light.hall', 'state' => 'on']],
        ]]);
        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => [
            'event_type' => 'zha_event',
            'data' => ['command' => 'toggle'],
        ]]);
        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => ['data' => ['nothing' => true]]]);

        EventLoopTicks::settle();
        $client->flushEvents();

        self::assertSame(['light.hall'], $changes);
        self::assertSame(['zha_event'], $events);
    }

    public function testNonAdminTokenIsReported(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('subscribe_events', [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized'],
        ]);

        $this->assertThrowsReason(HaClientError::AdministratorRequired, fn() => $client->subscribeAllEvents(static function (): void {}, static function (): void {}));
    }

    public function testTriggerSubscriptionSkipsEventsWithoutTrigger(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);
        $platforms = [];

        $socket->replyWhenSent('subscribe_trigger', ['type' => 'result', 'success' => true, 'result' => null]);
        $id = $client->subscribeTrigger(
            HaTriggerCollection::fromTriggers([HaTrigger::onSunset()]),
            [],
            static function (TriggerEvent $event) use (&$platforms): void {
                $platforms[] = $event->getPlatform();
            },
        );

        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => ['variables' => ['trigger' => ['platform' => 'sun']]]]);
        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => ['variables' => []]]);
        EventLoopTicks::settle();
        $client->flushEvents();

        self::assertSame(['sun'], $platforms);
    }

    public function testNonAdminTriggerSubscriptionIsReported(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('subscribe_trigger', [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized'],
        ]);

        $this->assertThrowsReason(
            HaClientError::AdministratorRequired,
            fn() => $client->subscribeTrigger(HaTriggerCollection::fromTriggers([HaTrigger::onSunset()]), [], static function (): void {}),
        );
    }

    public function testComponentVersionIsRead(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('stewart/version', ['type' => 'result', 'success' => true, 'result' => ['component_version' => '0.9.0', 'protocol' => 1]]);

        self::assertSame('0.9.0', $client->findComponentVersion()?->componentVersion);
    }

    public function testMissingComponentHasNoVersion(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('stewart/version', [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.'],
        ]);

        self::assertNull($client->findComponentVersion());
    }

    public function testComponentSessionDeliversOnlyKnownEvents(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);
        $events = [];

        $socket->replyWhenSent('stewart/session/subscribe', ['type' => 'result', 'success' => true, 'result' => null]);
        $id = $client->subscribeComponentSession(self::createSessionRequest(), static function (ComponentSessionEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => ['type' => 'sentence']]);
        $socket->queueFrame(['id' => $id, 'type' => 'event', 'event' => ['type' => 'session_replaced']]);
        EventLoopTicks::settle();
        $client->flushEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(SessionReplaced::class, $events[0]);
    }

    public function testNonAdminComponentSessionIsReported(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('stewart/session/subscribe', [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized'],
        ]);

        $this->assertThrowsReason(
            HaClientError::AdministratorRequired,
            static fn() => $client->subscribeComponentSession(self::createSessionRequest(), static function (): void {}),
        );
    }

    public function testServiceCallReturnsHaContext(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('call_service', ['type' => 'result', 'success' => true, 'result' => [
            'context' => ['id' => 'call-1', 'parent_id' => null, 'user_id' => 'stewart-user'],
        ]]);

        $response = $client->callService('light', 'turn_on');

        self::assertSame('call-1', $response->context?->id);
        self::assertSame('stewart-user', $response->context->userId);
    }

    public function testServiceCallWithoutContextLeavesItNull(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('call_service', ['type' => 'result', 'success' => true, 'result' => []]);

        self::assertNull($client->callService('light', 'turn_on')->context);
    }

    public function testCurrentUserIdIsRead(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('auth/current_user', ['type' => 'result', 'success' => true, 'result' => ['id' => 'stewart-user', 'name' => 'Stewart', 'is_admin' => true]]);

        self::assertSame('stewart-user', $client->getCurrentUserId());
    }

    public function testCurrentUserWithoutIdIsProtocolViolation(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('auth/current_user', ['type' => 'result', 'success' => true, 'result' => ['name' => 'Stewart']]);

        $this->assertThrowsReason(HaClientError::ProtocolViolation, static fn() => $client->getCurrentUserId());
    }

    /** @param array<string, mixed>|null $reply */
    #[DataProvider('provideServiceCallFailures')]
    public function testServiceCallFailureMapsReason(
        ?array $reply,
        float $nan,
        ServiceCallError $reason,
        ?string $errorCode,
        ?string $detail,
    ): void {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket, Duration::milliseconds(20));

        if ($reply !== null) {
            $socket->replyWhenSent('call_service', $reply);
        }

        try {
            $client->callService('light', 'turn_on', ['brightness' => $nan]);
        } catch (ServiceCallException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($errorCode, $e->context['errorCode'] ?? null);

            if ($detail !== null) {
                self::assertSame($detail, $e->context['detail'] ?? null);
            }

            return;
        }

        self::fail('The call should have failed.');
    }

    /** @return iterable<string, array{array<string, mixed>|null, float, ServiceCallError, ?string, ?string}> */
    public static function provideServiceCallFailures(): iterable
    {
        $failed = static fn(string $code, string $message): array => [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];

        yield 'rejected with a code' => [$failed('not_found', 'Entity not found'), 1.0, ServiceCallError::Rejected, 'not_found', 'Entity not found'];
        yield 'unauthorized' => [$failed('unauthorized', 'Unauthorized'), 1.0, ServiceCallError::Rejected, 'unauthorized', 'Unauthorized'];
        yield 'unencodable data' => [null, \NAN, ServiceCallError::Rejected, null, null];
    }

    public function testServiceCallTimesOut(): void
    {
        $timers = new ManualTimers();
        $client = self::connect(FakeWebsocketConnector::createAuthenticatedConnection(), Duration::milliseconds(20), $timers);
        async(static fn() => $timers->delay(Duration::milliseconds(20)))->ignore();

        $this->assertThrowsReason(ServiceCallError::TimedOut, fn() => $client->callService('light', 'turn_on'));
    }

    public function testServiceCallWithoutConnectionIsUnreachable(): void
    {
        $client = self::connect(FakeWebsocketConnector::createAuthenticatedConnection());
        $client->close();

        $this->expectException(ServiceCallException::class);

        try {
            $client->callService('light', 'turn_on');
        } catch (ServiceCallException $e) {
            self::assertSame(ServiceCallError::Unreachable, $e->reason);

            throw $e;
        }
    }

    public function testEventFireReturnsHaContext(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('fire_event', ['type' => 'result', 'success' => true, 'result' => [
            'context' => ['id' => 'fire-1', 'parent_id' => null, 'user_id' => 'stewart-user'],
        ]]);

        $context = $client->fireEvent(new EventPayload('doorbell_pressed', ['button' => 'front']));

        self::assertSame('fire-1', $context->id);
        self::assertSame('stewart-user', $context->userId);
        self::assertSame(['button' => 'front'], $socket->listSentOfType('fire_event')[0]['event_data'] ?? null);
    }

    public function testEventFireWithoutContextIsUnknown(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('fire_event', ['type' => 'result', 'success' => true, 'result' => []]);

        self::assertSame('', $client->fireEvent(new EventPayload('doorbell_pressed'))->id);
    }

    #[DataProvider('provideEventFireRejections')]
    public function testEventFireRejectionKeepsHaCode(string $errorCode, string $message): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('fire_event', ['type' => 'result', 'success' => false, 'error' => ['code' => $errorCode, 'message' => $message]]);

        $e = $this->assertThrowsReason(EventFireError::Rejected, static fn() => $client->fireEvent(new EventPayload('doorbell_pressed')));
        self::assertSame($errorCode, $e->context['errorCode'] ?? null);
        self::assertSame($message, $e->context['detail'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideEventFireRejections(): iterable
    {
        yield 'rejected with a code' => ['invalid_format', 'Invalid event data'];
        yield 'unauthorized' => ['unauthorized', 'Unauthorized'];
    }

    public function testEventFireTimesOut(): void
    {
        $timers = new ManualTimers();
        $client = self::connect(FakeWebsocketConnector::createAuthenticatedConnection(), Duration::milliseconds(20), $timers);
        async(static fn() => $timers->delay(Duration::milliseconds(20)))->ignore();

        $this->assertThrowsReason(EventFireError::TimedOut, static fn() => $client->fireEvent(new EventPayload('doorbell_pressed')));
    }

    public function testEventFireWithoutConnectionIsUnreachable(): void
    {
        $client = self::connect(FakeWebsocketConnector::createAuthenticatedConnection());
        $client->close();

        $this->assertThrowsReason(EventFireError::Unreachable, static fn() => $client->fireEvent(new EventPayload('doorbell_pressed')));
    }

    public function testEntityRegistrySkipsInvalidEntries(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/list', ['type' => 'result', 'success' => true, 'result' => [
            ['entity_id' => 'light.hall', 'platform' => 'hue', 'disabled_by' => null, 'hidden_by' => null],
            ['entity_id' => 'light.attic', 'disabled_by' => 'user'],
            ['entity_id' => '', 'disabled_by' => null],
            'not an entry',
        ]]);

        $registry = $client->getEntityRegistry();

        self::assertCount(2, $registry);
        self::assertFalse($registry->find(new EntityId('light.hall'))?->isDisabled());
        self::assertTrue($registry->find(new EntityId('light.attic'))?->isDisabled());
    }

    public function testRegistryUpdateReturnsUpdatedEntry(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/update', ['type' => 'result', 'success' => true, 'result' => [
            'entity_entry' => ['entity_id' => 'light.hall', 'name' => 'Hall', 'hidden_by' => 'user', 'aliases' => [null]],
        ]]);

        $entry = $client->updateEntityRegistryEntry(new EntityId('light.hall'), new EntityRegistryUpdate()->withName('Hall')->withHidden(true));

        self::assertSame('Hall', $entry->name);
        self::assertTrue($entry->isHidden());
        self::assertSame([null], $entry->aliases);
        self::assertSame('Hall', $socket->listSentOfType('config/entity_registry/update')[0]['name'] ?? null);
    }

    public function testRegistryEntryLookupReadsExtendedEntry(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/get', ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => 'light.hall',
            'labels' => ['night'],
            'aliases' => ['Hall lamp'],
        ]]);

        self::assertSame(['Hall lamp'], $client->getEntityRegistryEntry(new EntityId('light.hall'))->aliases);
    }

    public function testRegistryEditOfUnknownEntityIsNotFound(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/get', ['type' => 'result', 'success' => false, 'error' => ['code' => 'not_found', 'message' => 'Entity not found']]);

        $this->assertThrowsReason(RegistryEditError::NotFound, static fn() => $client->getEntityRegistryEntry(new EntityId('light.gone')));
    }

    public function testRegistryUpdateRejectionCarriesDetail(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/update', ['type' => 'result', 'success' => false, 'error' => ['code' => 'invalid_info', 'message' => 'Device is disabled']]);

        $this->assertThrowsReason(
            RegistryEditError::Rejected,
            static fn() => $client->updateEntityRegistryEntry(new EntityId('light.hall'), new EntityRegistryUpdate()->withDisabled(false)),
        );
    }

    public function testRegistryUpdateWithoutEntryViolatesProtocol(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/entity_registry/update', ['type' => 'result', 'success' => true, 'result' => []]);

        $this->assertThrowsReason(
            RegistryEditError::Unreachable,
            static fn() => $client->updateEntityRegistryEntry(new EntityId('light.hall'), new EntityRegistryUpdate()->withName('Hall')),
        );
    }

    public function testRegistryJoinsAllFiveRegistries(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);
        $ok = static fn(array $rows): array => ['type' => 'result', 'success' => true, 'result' => $rows];

        $socket->replyWhenSent('config/area_registry/list', $ok([['area_id' => 'kitchen', 'name' => 'Kitchen', 'floor_id' => 'ground', 'labels' => ['cooking']]]));
        $socket->replyWhenSent('config/floor_registry/list', $ok([['floor_id' => 'ground', 'name' => 'Ground', 'level' => 0]]));
        $socket->replyWhenSent('config/label_registry/list', $ok([['label_id' => 'night', 'name' => 'Night']]));
        $socket->replyWhenSent('config/device_registry/list', $ok([['id' => 'bulb', 'name' => 'Bulb', 'area_id' => 'kitchen', 'labels' => []], ['id' => '']]));
        $socket->replyWhenSent('config/entity_registry/list', $ok([['entity_id' => 'light.ceiling', 'device_id' => 'bulb', 'area_id' => null, 'labels' => ['night']]]));

        $registry = $client->getRegistry();
        $placement = $registry->findEntityPlacement('light.ceiling');

        self::assertSame('kitchen', $placement->areaId?->value);
        self::assertSame('ground', $placement->floorId?->value);
        self::assertSame(['night', 'cooking'], $placement->labelIds->toStrings());
        self::assertCount(1, $registry->listDevices());
    }

    public function testFloorsAreEmptyOnOlderHomeAssistant(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/floor_registry/list', ['type' => 'result', 'success' => false, 'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.']]);

        self::assertTrue($client->getFloorRegistry()->isEmpty());
    }

    public function testRegistryRejectionPropagates(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('config/label_registry/list', ['type' => 'result', 'success' => false, 'error' => ['code' => 'unauthorized', 'message' => 'No.']]);

        $this->assertThrowsReason(HaClientError::CommandUnauthorized, static fn() => $client->getLabelRegistry());
    }

    public function testHistoryRowsBecomeEntityStates(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('history/history_during_period', ['type' => 'result', 'success' => true, 'result' => [
            'light.hall' => [['s' => 'off', 'lu' => 1_790_000_000], ['lu' => 1_790_000_001], ['s' => 'on', 'lu' => 1_790_000_060]],
        ]]);

        $history = $client->fetchHistory(new EntityId('light.hall'), self::createWindow(), HistoryDetail::StateChanges);

        self::assertSame(['off', 'on'], $history->states->mapToList(static fn(EntityState $state): string => $state->state));
        self::assertSame('light.hall', $history->entityId->value);
    }

    public function testEntityWithoutRecordedRowsHasEmptyHistory(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket);

        $socket->replyWhenSent('history/history_during_period', ['type' => 'result', 'success' => true, 'result' => []]);

        self::assertTrue($client->fetchHistory(new EntityId('light.hall'), self::createWindow(), HistoryDetail::StateChanges)->isEmpty());
    }

    /** @param array<string, mixed>|null $reply */
    #[DataProvider('provideHistoryFailures')]
    public function testHistoryFailureMapsReason(?array $reply, HistoryError $reason): void
    {
        $timers = new ManualTimers();
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $client = self::connect($socket, Duration::milliseconds(20), $timers);

        if ($reply === null) {
            async(static fn() => $timers->delay(Duration::milliseconds(20)))->ignore();
        } else {
            $socket->replyWhenSent('history/history_during_period', $reply);
        }

        $this->assertThrowsReason($reason, fn() => $client->fetchHistory(new EntityId('light.hall'), self::createWindow(), HistoryDetail::StateChanges));
    }

    /** @return iterable<string, array{array<string, mixed>|null, HistoryError}> */
    public static function provideHistoryFailures(): iterable
    {
        $failed = static fn(string $code): array => ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => 'failed']];

        yield 'no history integration' => [$failed('unknown_command'), HistoryError::RecorderUnavailable];
        yield 'invalid request' => [$failed('invalid_format'), HistoryError::Rejected];
        yield 'no answer' => [null, HistoryError::TimedOut];
    }

    public function testHistoryWithoutConnectionIsUnreachable(): void
    {
        $client = self::connect(FakeWebsocketConnector::createAuthenticatedConnection());
        $client->close();

        $this->assertThrowsReason(HistoryError::Unreachable, fn() => $client->fetchHistory(new EntityId('light.hall'), self::createWindow(), HistoryDetail::StateChanges));
    }

    private static function createWindow(): HistoryWindow
    {
        return new HistoryWindow(Instant::fromEpochMicroseconds(1_789_999_000_000_000), Instant::fromEpochMicroseconds(1_790_001_000_000_000));
    }

    private static function createSessionRequest(): ComponentSessionRequest
    {
        return new ComponentSessionRequest(ComponentInstance::parse('default'), '0.9.0', Duration::seconds(5));
    }

    private static function connect(FakeWebsocketConnection $socket, ?Duration $commandTimeout = null, ManualTimers $timers = new ManualTimers()): HaClient
    {
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: $commandTimeout ?? Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $timers,
            new NullLogger(),
            new FakeWebsocketConnector($socket),
        );

        $client = new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
        $client->connect();

        return $client;
    }
}
