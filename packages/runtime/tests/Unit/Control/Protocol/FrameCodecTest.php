<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\IsoInstantConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Tests\Fixtures\Wire\BrokenDurationConverter;
use Stewart\Store\StoreHealth;
use Stewart\Support\Json\JsonShape;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(FrameCodec::class)]
#[CoversClass(JsonShape::class)]
#[CoversClass(ControlException::class)]
#[CoversClass(JsonShapeException::class)]
#[CoversClass(LatencyHistogram::class)]
#[CoversClass(ServiceCallOutcome::class)]
final class FrameCodecTest extends TestCase
{
    use AssertsReason;

    private FrameCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new FrameCodec(FrameCodec::createControlWireMapper());
    }

    public function testHelloIsOneLineTheBrokerCanReadBack(): void
    {
        $hello = new Hello('secret', 'phpunit');
        $line = $this->codec->encodeFrame($hello);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringContainsString('"type":"hello"', $line);
        self::assertEquals($hello, $this->codec->decodeClientFrame($line));
    }

    public function testHelloWithoutAProtocolIsRefused(): void
    {
        $this->expectException(JsonShapeException::class);
        $this->expectExceptionMessage('"protocol" is missing; expected a whole number.');

        $this->codec->decodeClientFrame("{\"type\":\"hello\",\"token\":\"t\",\"client\":\"c\"}\n");
    }

    public function testAppStateTravelsByItsValue(): void
    {
        self::assertStringContainsString('"state":"failed"', $this->codec->encodeFrame(new SnapshotFrame(self::createSnapshot())));
    }

    public function testSmallServerFramesRoundTrip(): void
    {
        foreach ([
            new Welcome(ControlProtocol::VERSION),
            new Rejected('bad token'),
            new Bye('shutdown'),
        ] as $frame) {
            self::assertEquals($frame, $this->codec->decodeServerFrame($this->codec->encodeFrame($frame)));
        }
    }

    public function testWholeSnapshotRoundTrips(): void
    {
        $frame = new SnapshotFrame(self::createSnapshot());
        $decoded = $this->codec->decodeServerFrame($this->codec->encodeFrame($frame));

        self::assertInstanceOf(SnapshotFrame::class, $decoded);
        self::assertEquals($frame->snapshot, $decoded->snapshot);

        [$demo, $broken] = $decoded->snapshot->apps;
        self::assertSame(AppState::Failed, $broken->state);
        self::assertSame('boom', $broken->lastFailure?->message);
        self::assertNull($demo->serviceCalls[1]->latency, 'A refused call carries no latency.');
    }

    public function testHistogramNamesTheBoundAQuantileFallsUnder(): void
    {
        $histogram = new LatencyHistogram([5, 10, 25], [2, 9, 10], 11, Duration::milliseconds(200));

        self::assertSame(5, $histogram->quantileBoundMs(0.1));
        self::assertSame(10, $histogram->quantileBoundMs(0.5));
        self::assertSame(25, $histogram->quantileBoundMs(0.9));
        self::assertNull($histogram->quantileBoundMs(0.99), 'One call was slower than the last bound.');
        self::assertNull(new LatencyHistogram([5, 10, 25], [0, 0, 0], 0, Duration::zero())->quantileBoundMs(0.5), 'No timed call, no quantile.');
    }

    public function testFailureReasonMapsToAnOutcome(): void
    {
        self::assertSame(ServiceCallOutcome::Refused, ServiceCallOutcome::failedWith(ServiceCallError::Overloaded));
        self::assertSame(ServiceCallOutcome::TimedOut, ServiceCallOutcome::failedWith(ServiceCallError::TimedOut));
        self::assertFalse(ServiceCallOutcome::Succeeded->isFailure());
        self::assertTrue(ServiceCallOutcome::Rejected->isFailure());
    }

    public function testDaemonWithoutAStoreSendsNoStoreHealth(): void
    {
        $snapshot = self::createSnapshot();
        $withoutStore = new RuntimeSnapshot($snapshot->takenAt, $snapshot->daemon, $snapshot->connection, $snapshot->broker, $snapshot->workers, $snapshot->apps, $snapshot->subscriptions);
        $decoded = $this->codec->decodeServerFrame($this->codec->encodeFrame(new SnapshotFrame($withoutStore)));

        self::assertInstanceOf(SnapshotFrame::class, $decoded);
        self::assertNull($decoded->snapshot->store);
    }

    public function testInstantsEncodeAsIsoAndDurationsAsMicroseconds(): void
    {
        $data = $this->getSnapshotData();

        self::assertSame('2023-11-14T22:13:21.000000Z', $data['taken_at']);
        self::assertIsArray($data['connection']);
        self::assertSame(4_500_000, $data['connection']['last_outage_us']);
    }

    public function testInstantThatIsNotIsoTextIsRefused(): void
    {
        $data = $this->getSnapshotData();
        $data['taken_at'] = 'yesterday';

        $e = $this->assertThrowsReason(JsonShapeError::InstantInvalid, fn() => $this->codec->decodeServerFrame('{"type":"snapshot","snapshot":' . json_encode($data, \JSON_THROW_ON_ERROR) . "}\n"));

        self::assertStringContainsString('"snapshot.taken_at" is invalid: Instant "yesterday" is not ISO-8601.', $e->getMessage());
    }

    public function testNegativeDurationIsRefused(): void
    {
        $this->expectException(JsonShapeException::class);
        $this->expectExceptionMessage('"snapshot.connection.last_outage_us" is -1; expected a non-negative whole number of microseconds');

        $data = $this->getSnapshotData();
        $data['connection'] = ['phase' => 'connected', 'since' => null, 'reconnects' => 0, 'last_outage_us' => -1];

        $this->codec->decodeServerFrame('{"type":"snapshot","snapshot":' . json_encode($data, \JSON_THROW_ON_ERROR) . "}\n");
    }

    public function testMalformedInputNamesWhatIsWrong(): void
    {
        $badApp = array_replace($this->getSnapshotData(), ['apps' => [['id' => 'x', 'class' => 'X', 'worker_id' => 0, 'state' => 'Flying']]]);
        $numericState = array_replace($this->getSnapshotData(), ['apps' => [['id' => 'x', 'class' => 'X', 'worker_id' => 0, 'state' => 3]]]);

        foreach ([
            'not json' => "{\n",
            'not an object' => "[1,2]\n",
            'no type' => "{\"reason\":\"x\"}\n",
            'unknown type' => "{\"type\":\"dance\"}\n",
            'wrong field type' => "{\"type\":\"bye\",\"reason\":7}\n",
            'a retired frame' => "{\"type\":\"tail\",\"kind\":\"events\",\"entry\":{}}\n",
            'bad app state' => '{"type":"snapshot","snapshot":' . json_encode($badApp, \JSON_THROW_ON_ERROR) . "}\n",
            'numeric app state' => '{"type":"snapshot","snapshot":' . json_encode($numericState, \JSON_THROW_ON_ERROR) . "}\n",
        ] as $case => $line) {
            try {
                $this->codec->decodeServerFrame($line);
                self::fail($case . ' should have been refused');
            } catch (StewartException $e) {
                self::assertNotSame('', $e->getMessage(), $case);
            }
        }
    }

    public function testDecoderBugIsFrameUndecodable(): void
    {
        $codec = new FrameCodec(new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new IsoInstantConverter(), new BrokenDurationConverter()]))));

        $e = $this->assertThrowsReason(ControlError::FrameUndecodable, fn() => $codec->decodeServerFrame($this->codec->encodeFrame(new SnapshotFrame(self::createSnapshot()))));

        self::assertSame('snapshot', $e->context['frameType'] ?? null);
    }

    public function testClientAndServerFramesAreNotInterchangeable(): void
    {
        $e = $this->assertThrowsReason(JsonShapeError::UnexpectedValue, fn() => $this->codec->decodeClientFrame($this->codec->encodeFrame(new Bye('x'))));

        self::assertStringContainsString('"type" is "bye"; expected a client frame type', $e->getMessage());
    }

    public function testEveryFrameCanBeDescribed(): void
    {
        $mapper = FrameCodec::createControlWireMapper();

        foreach ([Hello::class, Welcome::class, SnapshotFrame::class, Rejected::class, Bye::class] as $frame) {
            self::assertSame($frame, $mapper->shapes->resolveClassShape($frame)->class);
        }
    }

    /** @return array<array-key, mixed> */
    private function getSnapshotData(): array
    {
        $frame = json_decode($this->codec->encodeFrame(new SnapshotFrame(self::createSnapshot())), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($frame);
        self::assertIsArray($frame['snapshot']);

        return $frame['snapshot'];
    }

    private static function createSnapshot(): RuntimeSnapshot
    {
        return new RuntimeSnapshot(
            takenAt: self::createInstantAt(1700000001.0),
            daemon: new DaemonInfo(42, self::createInstantAt(1699999000.0), 8_000_000, '0.1.0-dev', 'Europe/Budapest', 2000, '2026.8.1'),
            connection: new ConnectionState(ConnectionPhase::Connected, self::createInstantAt(1699999001.0), 2, Duration::milliseconds(4_500)),
            broker: new BrokerStats(2, 1, 3, 1, new RoutingStats(5, 2, 40, 100, 7)),
            workers: [
                new WorkerStatus(0, WorkerPhase::Live, ['demo', 'broken'], 4242, true, 0, Duration::microseconds(1_500), 4_000_000, self::createInstantAt(1700000000.0), 0, null, new OutboxStatus(3, 0, 1, 40, 12), 1),
                new WorkerStatus(1, WorkerPhase::RestartScheduled, ['other'], null, false, 2, null, null, null, 1, self::createInstantAt(1700000005.0), null, 0),
            ],
            apps: [
                new AppStatus(
                    id: 'demo',
                    class: 'Stewart\\Runtime\\Tests\\Fixtures\\Apps\\Demo',
                    workerId: 0,
                    state: AppState::Running,
                    pause: null,
                    configPauseOverride: null,
                    reportedAt: self::createInstantAt(1700000000.0),
                    subscriptions: 2,
                    schedules: 1,
                    counters: new AppCounters(3, 1, 1, 1, 0),
                    serviceCalls: [
                        new ServiceCallStats(ServiceCallOutcome::Succeeded, 4, new LatencyHistogram([5, 10], [1, 4], 4, Duration::microseconds(18_250))),
                        new ServiceCallStats(ServiceCallOutcome::Refused, 1, null),
                    ],
                    lastFailure: null,
                ),
                new AppStatus(
                    id: 'broken',
                    class: 'Stewart\\Runtime\\Tests\\Fixtures\\Apps\\Broken',
                    workerId: 0,
                    state: AppState::Failed,
                    pause: null,
                    configPauseOverride: null,
                    reportedAt: self::createInstantAt(1700000000.0),
                    subscriptions: 0,
                    schedules: 0,
                    counters: new AppCounters(failures: 1),
                    serviceCalls: [],
                    lastFailure: new FailureReport(AppFailurePhase::Initialize, 'RuntimeException', 'boom', null, 'timed_out', self::createInstantAt(1700000000.1)),
                ),
                new AppStatus('other', 'Other', 1, null, null, null, null, 0, 0, new AppCounters(), [], null),
            ],
            subscriptions: [
                new RegistrationInfo('w0:0', 0, 'demo', SubscriptionKind::StateChange, 'exact:light.hall', true),
                new RegistrationInfo('w1:0', 1, 'other', SubscriptionKind::Topic, 'glob:house.*', false),
            ],
            store: new StoreHealth(false, 'The store at redis://valkey:6379/0 is unreachable: connection refused', self::createInstantAt(1699999995.0)),
        );
    }

    private static function createInstantAt(float $epochSeconds): Instant
    {
        return Instant::fromEpochMicroseconds((int) round($epochSeconds * 1_000_000));
    }
}
