<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Integration;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Client\Tests\Fixtures\FakeHaServer;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(HaClient::class)]
#[CoversClass(HaConnection::class)]
final class HaClientOverWebsocketTest extends TestCase
{
    use AssertsReason;

    private const float WAIT_SECONDS = 5;

    private const int SMALL_SIZE_LIMIT = 1024;

    private FakeHaServer $server;

    private ?HaClient $client = null;

    /** @var list<HaClientException> */
    private array $disconnects = [];

    /** @var DeferredFuture<HaClientException> */
    private DeferredFuture $firstDisconnect;

    protected function setUp(): void
    {
        $this->server = FakeHaServer::start();
        $this->firstDisconnect = new DeferredFuture();
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->server->stop();
    }

    public function testAuthenticatesAndAnswersCommand(): void
    {
        $this->server->answerCommand('get_config', ['time_zone' => 'Europe/Budapest']);

        $client = $this->connectClient();

        self::assertSame(FakeHaServer::HA_VERSION, $client->getHaVersion());
        self::assertSame('Europe/Budapest', $client->getTimeZone()->getName());
    }

    public function testHistoryRoundTripsOverSocket(): void
    {
        $this->server->answerCommand('history/history_during_period', ['light.hall' => [['s' => 'on', 'lu' => 1_790_000_000.5]]]);
        $startsAt = Instant::fromEpochMicroseconds(1_789_999_000_000_000);

        $history = $this->connectClient()->fetchHistory(
            new EntityId('light.hall'),
            new HistoryWindow($startsAt, $startsAt->plus(Duration::hours(1))),
            HistoryDetail::StateChanges,
        );

        $startState = $history->getStateAtStart();

        self::assertSame('on', $startState?->state);
        self::assertSame(1_790_000_000_500_000, $startState->lastChangedAt?->toEpochMicroseconds());
    }

    public function testRejectedTokenFailsConnect(): void
    {
        $this->server->rejectToken('Invalid access token');

        $this->assertThrowsReason(HaClientError::TokenRejected, fn() => $this->connectClient());
    }

    public function testSubscribedEventReachesHandler(): void
    {
        $client = $this->connectClient();
        /** @var DeferredFuture<HaEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $client->subscribeAllEvents(
            static function (StateChange $change): void {},
            static function (HaEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, ['event_type' => 'stewart_demo', 'data' => ['source' => 'test']]);

        $event = $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame('stewart_demo', $event->type);
        self::assertSame(['source' => 'test'], $event->data);
    }

    public function testAbruptDropFailsPendingCommand(): void
    {
        $this->server->holdCommand('call_service');
        $client = $this->connectClient();

        $pending = async(static fn() => $client->callService('light', 'turn_on'));
        EventLoopTicks::settle();
        $this->server->dropConnections();

        $this->assertThrowsReason(ServiceCallError::Unreachable, fn() => $pending->await(new TimeoutCancellation(self::WAIT_SECONDS)));
        self::assertSame(HaClientError::ConnectionDropped, $this->awaitFirstDisconnect()->reason);
        EventLoopTicks::settle();
        self::assertCount(1, $this->disconnects);
        self::assertFalse($client->isConnected());
    }

    public function testCloseFrameNamesHomeAssistant(): void
    {
        $this->connectClient();

        $this->server->closeConnections(1001, 'restarting');
        $lost = $this->awaitFirstDisconnect();

        self::assertSame(HaClientError::ConnectionDropped, $lost->reason);
        self::assertStringContainsString('closed by Home Assistant: restarting', $lost->getMessage());
    }

    public function testOversizeMessageDropsConnection(): void
    {
        $this->connectClient(sizeLimit: self::SMALL_SIZE_LIMIT);

        $this->server->sendText(JsonEncoder::encodeToJson(['type' => 'event', 'padding' => str_repeat('x', 4 * self::SMALL_SIZE_LIMIT)]));

        self::assertSame(HaClientError::MessageTooLarge, $this->awaitFirstDisconnect()->reason);
    }

    public function testSilentServerTimesOutConnect(): void
    {
        $this->server->withholdGreeting();

        $this->assertThrowsReason(HaClientError::ConnectTimedOut, fn() => $this->connectClient(connectTimeout: Duration::milliseconds(200)));
    }

    private function connectClient(int $sizeLimit = 64 * 1024 * 1024, ?Duration $connectTimeout = null): HaClient
    {
        $client = HaClient::fromConnectionConfig(
            new ConnectionConfig(
                url: $this->server->getUrl(),
                token: 'test-token',
                connectTimeout: $connectTimeout ?? Duration::seconds(5),
                commandTimeout: Duration::seconds(5),
                heartbeatInterval: null,
                messageSizeLimit: $sizeLimit,
                frameSizeLimit: $sizeLimit,
            ),
            new RevoltTimers(),
        );
        $client->onDisconnect(function (HaClientException $lost): void {
            $this->disconnects[] = $lost;

            if (!$this->firstDisconnect->isComplete()) {
                $this->firstDisconnect->complete($lost);
            }
        });

        $this->client = $client;
        $client->connect();

        return $client;
    }

    private function awaitFirstDisconnect(): HaClientException
    {
        return $this->firstDisconnect->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }
}
