<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Integration;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\State\StateUpdate;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Support\Wire\EventPayload;
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

    public function testCoalescingIsDeclaredAfterAuthentication(): void
    {
        $this->server->answerCommand('get_config', ['time_zone' => 'Europe/Budapest']);

        $this->connectClient()->getTimeZone();

        self::assertSame([['type' => 'supported_features', 'features' => ['coalesce_messages' => 1], 'id' => 1]], $this->server->listReceivedCommands('supported_features'));
    }

    /** @return iterable<string, array{bool}> */
    public static function provideUnansweredDeclarations(): iterable
    {
        yield 'refused' => [false];
        yield 'never answered' => [true];
    }

    #[DataProvider('provideUnansweredDeclarations')]
    public function testUnansweredFeatureDeclarationLeavesTheConnectionWorking(bool $held): void
    {
        if ($held) {
            $this->server->holdCommand('supported_features');
        } else {
            $this->server->rejectCommand('supported_features', 'unknown_command', 'Unknown command.');
        }

        $this->server->answerCommand('get_config', ['time_zone' => 'Europe/Budapest']);

        self::assertSame('Europe/Budapest', $this->connectClient()->getTimeZone()->getName());
        self::assertSame([], $this->disconnects);
    }

    public function testServiceCallContextAndCurrentUserOverSocket(): void
    {
        $this->server->answerCommand('call_service', ['context' => ['id' => 'call-1', 'parent_id' => null, 'user_id' => 'stewart-user']]);
        $this->server->answerCommand('auth/current_user', ['id' => 'stewart-user', 'name' => 'Stewart']);

        $client = $this->connectClient();

        self::assertSame('call-1', $client->callService('light', 'turn_on')->context?->id);
        self::assertSame('stewart-user', $client->getCurrentUserId());
    }

    public function testEventFireRoundTripsOverSocket(): void
    {
        $this->server->answerCommand('fire_event', ['context' => ['id' => 'fire-1', 'parent_id' => null, 'user_id' => 'stewart-user']]);

        $context = $this->connectClient()->fireEvent(new EventPayload('doorbell_pressed', ['button' => 'front']));

        self::assertSame('fire-1', $context->id);
        self::assertSame(['button' => 'front'], $this->server->listReceivedCommands('fire_event')[0]['event_data'] ?? null);
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

    public function testStateUpdateArrivesWithoutTheOldState(): void
    {
        $client = $this->connectClient();
        $updates = [];
        /** @var DeferredFuture<HaEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $client->subscribeStateUpdatesAndEvents(
            static function (StateUpdate $update) use (&$updates): void {
                $updates[] = $update;
            },
            static function (HaEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, ['event_type' => 'state_changed', 'data' => [
            'entity_id' => 'light.hall',
            'old_state' => ['entity_id' => 'light.hall', 'state' => 'off'],
            'new_state' => ['entity_id' => 'light.hall', 'state' => 'on'],
        ]]);
        $this->server->pushEvent($subscriptionId, ['event_type' => 'stewart_demo', 'data' => []]);

        self::assertSame('stewart_demo', $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS))->type);
        self::assertCount(1, $updates);
        self::assertSame('light.hall', $updates[0]->entityId->value);
        self::assertSame('on', $updates[0]->to?->state);
    }

    public function testInvalidUtf8FrameIsDroppedAndTheConnectionStays(): void
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
        $this->server->sendUncheckedText('{"id":' . $subscriptionId . ',"type":"event","event":{"event_type":"' . "\xff" . '"}}');
        $this->server->pushEvent($subscriptionId, ['event_type' => 'stewart_demo', 'data' => []]);

        self::assertSame('stewart_demo', $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS))->type);
        self::assertSame([], $this->disconnects);
        self::assertTrue($client->isConnected());
    }

    public function testCoalescedFrameReachesHandlersInOrder(): void
    {
        $client = $this->connectClient();
        $received = [];
        /** @var DeferredFuture<null> $both */
        $both = new DeferredFuture();

        $subscriptionId = $client->subscribeAllEvents(
            static function (StateChange $change): void {},
            static function (HaEvent $event) use (&$received, $both): void {
                $received[] = $event->type;

                if (\count($received) === 2) {
                    $both->complete();
                }
            },
        );
        $this->server->sendText(JsonEncoder::encodeToJson([
            ['id' => $subscriptionId, 'type' => 'event', 'event' => ['event_type' => 'first', 'data' => []]],
            ['id' => $subscriptionId, 'type' => 'event', 'event' => ['event_type' => 'second', 'data' => []]],
        ]));

        $both->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame(['first', 'second'], $received);
    }

    public function testCoalescedFrameWithANonObjectIsDroppedAndTheConnectionStays(): void
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
        $this->server->sendText(JsonEncoder::encodeToJson([['id' => $subscriptionId, 'type' => 'event', 'event' => ['event_type' => 'dropped', 'data' => []]], 7]));
        $this->server->pushEvent($subscriptionId, ['event_type' => 'stewart_demo', 'data' => []]);

        self::assertSame('stewart_demo', $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS))->type);
        self::assertSame([], $this->disconnects);
    }

    public function testTriggerSubscriptionDeliversTriggerEvent(): void
    {
        $client = $this->connectClient();
        /** @var DeferredFuture<TriggerEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $client->subscribeTrigger(
            HaTriggerCollection::fromList([HaTrigger::onSunset(id: 'dusk')]),
            ['room' => 'hall'],
            static function (TriggerEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, [
            'variables' => ['trigger' => ['platform' => 'sun', 'event' => 'sunset', 'id' => 'dusk', 'idx' => '0']],
            'context' => ['id' => 'ctx-1'],
        ]);

        $event = $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame('dusk', $event->getTriggerId());
        self::assertSame('ctx-1', $event->context?->id);
        self::assertSame(
            [['trigger' => 'sun', 'event' => 'sunset', 'id' => 'dusk']],
            $this->server->listReceivedCommands('subscribe_trigger')[0]['trigger'] ?? null,
        );
        self::assertSame(['room' => 'hall'], $this->server->listReceivedCommands('subscribe_trigger')[0]['variables'] ?? null);
    }

    public function testRejectedTriggerSubscriptionThrows(): void
    {
        $this->server->rejectCommand('subscribe_trigger', 'invalid_format', 'Unknown trigger');
        $client = $this->connectClient();

        $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->subscribeTrigger(
                HaTriggerCollection::fromList([HaTrigger::fromArray(['trigger' => 'nonsense'])]),
                [],
                static function (TriggerEvent $event): void {},
            ),
        );
    }

    public function testUnsubscribedTriggerStopsDelivery(): void
    {
        $client = $this->connectClient();
        $delivered = [];

        $subscriptionId = $client->subscribeTrigger(
            HaTriggerCollection::fromList([HaTrigger::onSunrise()]),
            [],
            static function (TriggerEvent $event) use (&$delivered): void {
                $delivered[] = $event;
            },
        );
        $client->unsubscribeEvents($subscriptionId);
        $this->server->pushEvent($subscriptionId, ['variables' => ['trigger' => ['platform' => 'sun']]]);
        EventLoopTicks::settle();
        $client->flushEvents();

        self::assertSame([], $delivered);
        self::assertSame($subscriptionId, $this->server->listReceivedCommands('unsubscribe_events')[0]['subscription'] ?? null);
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
