<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use Amp\DeferredFuture;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use RuntimeException;
use Stewart\Client\Connection\Command\GetConfig;
use Stewart\Client\Connection\Command\GetStates;
use Stewart\Client\Connection\Command\SubscribeEvents;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\EventDelivery;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;
use Throwable;

use function Amp\async;
use function Amp\delay;

#[CoversClass(HaConnection::class)]
#[CoversClass(EventDelivery::class)]
final class HaConnectionTest extends TestCase
{
    use AssertsReason;

    /** @var (Closure(Throwable): void)|null */
    private ?Closure $originalLoopErrorHandler;

    protected function setUp(): void
    {
        $this->originalLoopErrorHandler = EventLoop::getErrorHandler();
    }

    protected function tearDown(): void
    {
        EventLoop::setErrorHandler($this->originalLoopErrorHandler);
    }

    public function testEventCallbackCanAwaitCommand(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $answered = null;
        self::subscribe($socket, $connection, function () use ($connection, &$answered): void {
            $answered = $connection->send(new GetStates());
        });

        $socket->replyWhenSent('get_states', ['type' => 'result', 'success' => true, 'result' => ['ok']]);
        $socket->queueFrame(self::createEventFrame(1));
        EventLoopTicks::settleUntil(static function () use (&$answered): bool {
            return $answered !== null;
        });

        self::assertSame(['ok'], $answered);
    }

    public function testCommandResolvesWhileCallbackSuspended(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $gate = new DeferredFuture();
        self::subscribe($socket, $connection, static function () use ($gate): void {
            $gate->getFuture()->await();
        });

        $socket->queueFrame(self::createEventFrame(1));
        EventLoopTicks::settle();

        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => ['time_zone' => 'Europe/Budapest']]);

        self::assertSame(['time_zone' => 'Europe/Budapest'], $connection->send(new GetConfig()));

        $gate->complete();
    }

    public function testThrowingCallbackKeepsDelivering(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $seen = [];
        self::subscribe($socket, $connection, static function (array $event) use (&$seen): void {
            $seen[] = $event['sequence'];

            if ($event['sequence'] === 1) {
                throw new RuntimeException('handler blew up');
            }
        });

        $socket->queueFrame(self::createEventFrame(1, 1));
        $socket->queueFrame(self::createEventFrame(1, 2));
        EventLoopTicks::settleUntil(static function () use (&$seen): bool {
            return \count($seen) === 2;
        });

        self::assertSame([1, 2], $seen);
    }

    public function testCallbacksRunInArrivalOrder(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $seen = [];
        self::subscribe($socket, $connection, static function (array $event) use (&$seen): void {
            delay(0);
            $seen[] = $event['sequence'];
        });

        foreach ([1, 2, 3] as $sequence) {
            $socket->queueFrame(self::createEventFrame(1, $sequence));
        }

        EventLoopTicks::settle();
        $connection->flushEvents();

        self::assertSame([1, 2, 3], $seen);
    }

    public function testBacklogOverflowDropsConnection(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket, eventBufferLimit: 2);

        $lost = null;
        $connection->onDisconnect(static function (HaClientException $dropped) use (&$lost): void {
            $lost = $dropped;
        });

        $gate = new DeferredFuture();
        self::subscribe($socket, $connection, static function () use ($gate): void {
            $gate->getFuture()->await();
        });

        $socket->queueFrame(self::createEventFrame(1));
        EventLoopTicks::settle();

        foreach ([2, 3, 4] as $sequence) {
            $socket->queueFrame(self::createEventFrame(1, $sequence));
        }

        EventLoopTicks::settleUntil(static function () use (&$lost): bool {
            return $lost !== null;
        });

        self::assertSame(HaClientError::EventBacklogExceeded, $lost?->reason);
        self::assertTrue($socket->isClosed());
        self::assertFalse($connection->isConnected());

        $gate->complete();
    }

    public function testBacklogOverflowFailsCommandsBeforeClose(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket, eventBufferLimit: 2);

        $gate = new DeferredFuture();
        self::subscribe($socket, $connection, static function () use ($gate): void {
            $gate->getFuture()->await();
        });

        $pending = async(static fn(): array => $connection->send(new GetConfig()));
        $closeRelease = new Latch();
        $socket->holdCloseUntil($closeRelease);

        foreach ([1, 2, 3, 4] as $sequence) {
            $socket->queueFrame(self::createEventFrame(1, $sequence));
        }

        try {
            EventLoopTicks::settleUntil(static fn(): bool => $pending->isComplete());

            $this->assertThrowsReason(HaClientError::EventBacklogExceeded, fn() => $pending->await());
            self::assertFalse($connection->isConnected());
            self::assertSame(1, $closeRelease->countWaiters());
        } finally {
            $closeRelease->open();
            $gate->complete();
        }
    }

    public function testFlushWaitsForQueuedCallbacks(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $seen = [];
        self::subscribe($socket, $connection, static function (array $event) use (&$seen): void {
            delay(0);
            $seen[] = $event['sequence'];
        });

        $socket->queueFrame(self::createEventFrame(1, 1));
        $socket->queueFrame(self::createEventFrame(1, 2));
        EventLoopTicks::settle();

        $connection->flushEvents();

        self::assertSame([1, 2], $seen);
    }

    public function testFlushFailsOnDrop(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $gate = new DeferredFuture();
        self::subscribe($socket, $connection, static function () use ($gate): void {
            $gate->getFuture()->await();
        });

        $socket->queueFrame(self::createEventFrame(1));
        EventLoopTicks::settle();

        async(static fn() => $socket->close(1001, 'restarting'))->ignore();

        try {
            $this->assertThrowsReason(HaClientError::ConnectionDropped, fn() => $connection->flushEvents());
        } finally {
            $gate->complete();
        }
    }

    public function testRejectedToken(): void
    {
        $socket = new FakeWebsocketConnection();
        $socket->queueFrame(['type' => 'auth_required', 'ha_version' => '2026.9.0']);
        $socket->replyWhenSent('auth', ['type' => 'auth_invalid', 'message' => 'Invalid access token']);

        $connection = new HaConnection(self::createConnectionConfig(), new ManualTimers(), new NullLogger(), new FakeWebsocketConnector($socket));

        $this->assertThrowsReason(HaClientError::TokenRejected, fn() => $connection->connect());
    }

    public function testCloseDuringHandshakeCancels(): void
    {
        $connection = new HaConnection(self::createConnectionConfig(), new ManualTimers(), new NullLogger(), FakeWebsocketConnector::createStalled());

        async(static fn() => $connection->close())->ignore();

        $this->assertThrowsReason(HaClientError::ClosedLocally, fn() => $connection->connect());
    }

    public function testCloseDuringAuthenticationCancels(): void
    {
        $socket = new FakeWebsocketConnection();
        $connection = new HaConnection(self::createConnectionConfig(), new ManualTimers(), new NullLogger(), new FakeWebsocketConnector($socket));

        async(static fn() => $connection->close())->ignore();

        try {
            $this->assertThrowsReason(HaClientError::ClosedLocally, fn() => $connection->connect());
        } finally {
            self::assertTrue($socket->isClosed());
            self::assertFalse($connection->isConnected());
        }
    }

    public function testReconnectClosesEarlierSocket(): void
    {
        $first = FakeWebsocketConnector::createAuthenticatedConnection();
        $second = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = new HaConnection(self::createConnectionConfig(), new ManualTimers(), new NullLogger(), new FakeWebsocketConnector($first, $second));

        $connection->connect();
        $connection->connect();

        self::assertTrue($first->isClosed());
        self::assertFalse($second->isClosed());
        self::assertTrue($connection->isConnected());
    }

    public function testDisconnectHandlerRunsOnRemoteClose(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $lost = null;
        $connection->onDisconnect(static function (HaClientException $dropped) use (&$lost): void {
            $lost = $dropped;
        });

        $socket->close(1000, 'restarting');
        EventLoopTicks::settleUntil(static function () use (&$lost): bool {
            return $lost !== null;
        });

        self::assertSame(HaClientError::ConnectionDropped, $lost?->reason);
        self::assertStringContainsString('restarting', $lost->getMessage());
        self::assertFalse($connection->isConnected());
    }

    public function testLocalCloseIsNotBlamedOnHomeAssistant(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $lost = null;
        $connection->onDisconnect(static function (HaClientException $dropped) use (&$lost): void {
            $lost = $dropped;
        });

        $socket->close(1008, 'Exceeded unanswered PING limit');
        EventLoopTicks::settleUntil(static function () use (&$lost): bool {
            return $lost !== null;
        });

        self::assertSame(HaClientError::ConnectionDropped, $lost?->reason);
        self::assertStringContainsString('Exceeded unanswered PING limit (close code 1008)', $lost->getMessage());
        self::assertStringNotContainsString('by Home Assistant', $lost->getMessage());
    }

    public function testUntypedSubscriptionAsksForWholeBus(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $connection->subscribeEvents(new SubscribeEvents(), static function (): void {});

        self::assertArrayNotHasKey('event_type', $socket->listSentOfType('subscribe_events')[0]);
    }

    public function testCloseFailsPendingCommand(): void
    {
        $connection = self::connect(FakeWebsocketConnector::createAuthenticatedConnection());

        $pending = async(static fn(): array => $connection->send(new GetConfig()));
        EventLoopTicks::settle();

        $connection->close();

        $this->assertThrowsReason(HaClientError::ClosedLocally, fn() => $pending->await());
    }

    public function testSendCutByCloseReportsTheClose(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);
        $loopErrors = [];
        EventLoop::setErrorHandler(static function (Throwable $error) use (&$loopErrors): void {
            $loopErrors[] = $error;
        });

        $sendRelease = new Latch();
        $socket->holdSendsUntil($sendRelease);
        $pending = async(static fn(): array => $connection->send(new GetConfig()));
        EventLoopTicks::settle();

        $connection->close();
        $sendRelease->open();

        $this->assertThrowsReason(HaClientError::ClosedLocally, fn() => $pending->await());
        EventLoopTicks::settle();
        self::assertSame([], $loopErrors);
    }

    public function testRefusedSubscriptionLeavesNoHandler(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);
        $handled = 0;

        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => false, 'error' => ['code' => 'unknown_event', 'message' => 'no']]);

        try {
            $connection->subscribeEvents(new SubscribeEvents('state_changed'), static function () use (&$handled): void {
                ++$handled;
            });
            self::fail('Home Assistant refused the subscription.');
        } catch (HaClientException $e) {
            self::assertSame(HaClientError::CommandRejected, $e->reason);
        }

        $refusedId = $socket->listSentOfType('subscribe_events')[0]['id'];
        self::assertIsInt($refusedId);

        $socket->queueFrame(self::createEventFrame($refusedId));
        EventLoopTicks::settle();
        $connection->flushEvents();

        self::assertSame(0, $handled);
    }

    public function testNonObjectResultIsProtocolViolation(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $connection = self::connect($socket);

        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => 'nonsense']);

        $this->assertThrowsReason(HaClientError::ProtocolViolation, fn() => $connection->send(new GetConfig()));
    }

    public function testUnansweredCommandTimesOut(): void
    {
        $timers = new ManualTimers();
        $connection = self::connect(FakeWebsocketConnector::createAuthenticatedConnection(), commandTimeout: Duration::milliseconds(20), timers: $timers);
        async(static fn() => $timers->delay(Duration::milliseconds(20)))->ignore();

        $this->assertThrowsReason(HaClientError::CommandTimedOut, fn() => $connection->send(new GetConfig()));
    }

    public function testCommandBeforeConnectRefused(): void
    {
        $connection = new HaConnection(self::createConnectionConfig(), new ManualTimers(), new NullLogger(), new FakeWebsocketConnector());

        $this->assertThrowsReason(HaClientError::NotConnected, fn() => $connection->send(new GetConfig()));
    }

    public function testCommandAfterCloseRefused(): void
    {
        $connection = self::connect(FakeWebsocketConnector::createAuthenticatedConnection());
        $connection->close();

        $this->assertThrowsReason(HaClientError::NotConnected, fn() => $connection->send(new GetConfig()));
    }

    private static function connect(FakeWebsocketConnection $socket, int $eventBufferLimit = 10_000, ?Duration $commandTimeout = null, ManualTimers $timers = new ManualTimers()): HaConnection
    {
        $connection = new HaConnection(
            self::createConnectionConfig($eventBufferLimit, $commandTimeout),
            $timers,
            new NullLogger(),
            new FakeWebsocketConnector($socket),
        );

        $connection->connect();

        return $connection;
    }

    private static function createConnectionConfig(int $eventBufferLimit = 10_000, ?Duration $commandTimeout = null): ConnectionConfig
    {
        return new ConnectionConfig(
            url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
            token: 'test-token',
            connectTimeout: Duration::seconds(1),
            commandTimeout: $commandTimeout ?? Duration::seconds(1),
            heartbeatInterval: null,
            eventBufferLimit: $eventBufferLimit,
        );
    }

    /** @param Closure(array<string, mixed>): void $handler */
    private static function subscribe(FakeWebsocketConnection $socket, HaConnection $connection, Closure $handler): void
    {
        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);

        $connection->subscribeEvents(new SubscribeEvents('state_changed'), $handler);
    }

    /** @return array<string, mixed> */
    private static function createEventFrame(int $subscriptionId, int $sequence = 1): array
    {
        return [
            'id' => $subscriptionId,
            'type' => 'event',
            'event' => ['event_type' => 'state_changed', 'sequence' => $sequence],
        ];
    }
}
