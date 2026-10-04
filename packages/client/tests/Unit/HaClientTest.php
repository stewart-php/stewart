<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\HaClient;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
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

        $client = new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new NullLogger());
        $client->connect();

        return $client;
    }
}
