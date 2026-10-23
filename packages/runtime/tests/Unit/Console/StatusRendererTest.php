<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Console\StatusFormatter;
use Stewart\Runtime\Console\StatusRenderer;
use Stewart\Runtime\Console\StatusView;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppPauseStatus;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\DeployFailure;
use Stewart\Runtime\Control\Protocol\Status\DeployStatus;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Control\Protocol\Status\GeneratedCodeStatus;
use Stewart\Runtime\Control\Protocol\Status\HaCallStats;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\MqttDropCount;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\ReloadAttempt;
use Stewart\Runtime\Control\Protocol\Status\ReloadStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ScheduleStatus;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\HaCallOutcome;
use Stewart\Runtime\Model\MqttDropReason;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Store\StoreHealth;
use Stewart\Support\Wire\GeneratedFormat;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(StatusRenderer::class)]
#[CoversClass(StatusFormatter::class)]
// Set UPDATE_GOLDEN=1 to rewrite tests/Fixtures/Control/status.txt from the snapshot below.
final class StatusRendererTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../Fixtures/Control/status.txt';

    private const string APP_GOLDEN = __DIR__ . '/../../Fixtures/Control/status-app.txt';

    private const float NOW = 1700003600.0;

    public function testEverySectionIsRenderedFromTheSnapshot(): void
    {
        $snapshot = self::createSnapshot(new ComponentStatus(ComponentState::Active, self::createInstantAt(self::NOW - 30.0), 'default', '0.9.0', 1), self::createDriftedCode());

        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, $snapshot);
        $text = $output->fetch();

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $text);
        }

        self::assertStringEqualsFile(self::GOLDEN, $text);
    }

    public function testAppDetailAddsTheAppsSubscriptionsAndSchedules(): void
    {
        $snapshot = self::createSnapshot(null, self::createDriftedCode())->withOnlyApps(['demo']);

        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, $snapshot, StatusView::AppDetail);
        $text = $output->fetch();

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::APP_GOLDEN, $text);
        }

        self::assertStringEqualsFile(self::APP_GOLDEN, $text);
    }

    public function testSeveralAppsNameTheAppOfEachSubscription(): void
    {
        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, self::createSnapshot(null, self::createDriftedCode()), StatusView::AppDetail);

        self::assertMatchesRegularExpression('/^\s*app\s+kind\s+match/m', $output->fetch());
    }

    public function testFormatHelpersRead(): void
    {
        self::assertSame('1.5 GiB', new StatusFormatter()->formatBytes(1_610_612_736));
        self::assertSame('512 KiB', new StatusFormatter()->formatBytes(524_288));
        self::assertSame('12 B', new StatusFormatter()->formatBytes(12));
        self::assertSame('-', new StatusFormatter()->formatBytes(null));
        self::assertSame('1.2 s', new StatusFormatter()->formatElapsed(Duration::microseconds(1_234_000)));
        self::assertSame('0.3 ms', new StatusFormatter()->formatElapsed(Duration::microseconds(300)));
        self::assertSame('never', new StatusFormatter()->formatTimeAgo(null, self::createInstantAt(10.0)));
        self::assertSame('2d 03h', new StatusFormatter()->formatDuration(Duration::seconds(2 * 86400 + 3 * 3600 + 5)));
        self::assertSame('in 4m 00s', new StatusFormatter()->formatNextRun(self::createInstantAt(250.0), self::createInstantAt(10.0)));
        self::assertSame('due', new StatusFormatter()->formatNextRun(self::createInstantAt(5.0), self::createInstantAt(10.0)), 'A run the last probe expected has passed.');
        self::assertSame('-', new StatusFormatter()->formatNextRun(null, self::createInstantAt(10.0)));
    }

    #[DataProvider('provideComponentRows')]
    public function testComponentRowExplainsState(ComponentStatus $component, string $expected): void
    {
        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, self::createSnapshot($component, self::createDriftedCode()));

        self::assertStringContainsString('component      ' . $expected, $output->fetch());
    }

    #[DataProvider('provideGeneratedRows')]
    public function testGeneratedRowSaysWhatToDo(?GeneratedCodeStatus $generated, string $expected): void
    {
        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, self::createSnapshot(null, $generated));

        self::assertStringContainsString('generated      ' . $expected, $output->fetch());
    }

    /** @return iterable<string, array{?GeneratedCodeStatus, string}> */
    public static function provideGeneratedRows(): iterable
    {
        $checkedAt = self::createInstantAt(self::NOW - 120.0);

        yield 'up to date' => [new GeneratedCodeStatus(GeneratedFormat::VERSION, GeneratedFormat::VERSION, [], [], $checkedAt), 'up to date, checked 2m 00s ago'];
        yield 'older format' => [new GeneratedCodeStatus(5, 6, [], [], $checkedAt), 'format 5, expected 6; run stewart generate'];
        yield 'never generated' => [null, 'not generated'];
    }

    /** @return iterable<string, array{ComponentStatus, string}> */
    public static function provideComponentRows(): iterable
    {
        $since = self::createInstantAt(self::NOW - 120.0);

        yield 'missing' => [new ComponentStatus(ComponentState::Missing, $since, 'default'), 'missing since 2m 00s ago, install the stewart integration in Home Assistant'];
        yield 'protocol mismatch' => [new ComponentStatus(ComponentState::ProtocolMismatch, $since, 'default', '0.10.0', 3), 'protocol mismatch since 2m 00s ago, version 0.10.0, protocol 3 where Stewart speaks 2'];
        yield 'replaced' => [new ComponentStatus(ComponentState::Replaced, $since, 'upstairs', '0.9.0', 1), 'replaced since 2m 00s ago, version 0.9.0, another Stewart uses expose.instance "upstairs"'];
    }

    private static function createSnapshot(?ComponentStatus $component, ?GeneratedCodeStatus $generated): RuntimeSnapshot
    {
        $now = self::NOW;

        return new RuntimeSnapshot(
            takenAt: self::createInstantAt($now),
            daemon: new DaemonInfo(42, self::createInstantAt($now - 3725.0), 12_582_912, '0.1.0-test', 'Europe/Budapest', 2000, '2026.8.1'),
            connection: new ConnectionState(ConnectionPhase::Connected, self::createInstantAt($now - 30.0), 1, Duration::milliseconds(4_500)),
            broker: new BrokerStats(2, 1, 1, 0, new RoutingStats(2, 1, 40, 100, 7)),
            workers: [
                new WorkerStatus(0, WorkerPhase::Live, ['demo'], 4242, true, 0, Duration::microseconds(1_500), 4_194_304, self::createInstantAt($now - 7.0), 0, null, new OutboxStatus(2, 4, 5, 40, 9), 1),
                new WorkerStatus(1, WorkerPhase::RestartScheduled, ['echo'], null, false, 0, null, null, null, 2, self::createInstantAt($now + 4.0), null, 0, restarts: 5, quarantines: 1),
            ],
            apps: [
                new AppStatus(
                    id: 'demo',
                    class: 'Demo',
                    workerId: 0,
                    state: AppState::Running,
                    pause: new AppPauseStatus(self::createInstantAt($now - 125.0), AppPauseSource::Control),
                    configPauseOverride: null,
                    reportedAt: self::createInstantAt($now - 7.0),
                    subscriptions: 1,
                    schedules: 1,
                    exposedEntities: 2,
                    counters: new AppCounters(delivered: 12, subscriptionDropped: 1, scheduleRuns: 3, publishes: 2, failures: 1, suppressed: 4),
                    haCalls: [
                        new HaCallStats(HaCallOutcome::Succeeded, 4, new LatencyHistogram([5, 10], [1, 3], 4, Duration::milliseconds(40))),
                        new HaCallStats(HaCallOutcome::TimedOut, 1, new LatencyHistogram([5, 10], [0, 0], 1, Duration::seconds(30))),
                    ],
                    lastFailure: new FailureReport(AppFailurePhase::Handler, 'RuntimeException', 'lamp offline', null, 'unreachable', self::createInstantAt($now - 60.0)),
                    lastHandledAt: self::createInstantAt($now - 90.0),
                    nextRunAt: self::createInstantAt($now + 240.0),
                ),
                new AppStatus('echo', 'Echo', 1, AppState::Running, null, new AppPauseStatus(self::createInstantAt($now - 300.0), AppPauseSource::Control), null, 0, 0, 0, new AppCounters(), [], null),
            ],
            subscriptions: [
                new RegistrationInfo('w0:1', 0, 'demo', SubscriptionKind::StateChange, 'exact:light.hall', true, 14, 12, 1, self::createInstantAt($now - 95.0), self::createInstantAt($now - 90.0)),
                new RegistrationInfo('w0:3', 0, 'demo', SubscriptionKind::Topic, 'exact:house.mode', true),
                new RegistrationInfo('w1:1', 1, 'echo', SubscriptionKind::Topic, 'any', false),
            ],
            store: new StoreHealth(false, 'connection refused', self::createInstantAt($now - 20.0)),
            deploy: new DeployStatus(
                str_repeat('a', 40),
                self::createInstantAt($now - 15.0),
                2,
                new DeployFailure(str_repeat('b', 40), 'apps/Porch.php failed to load: syntax error', self::createInstantAt($now - 120.0)),
            ),
            component: $component,
            reload: new ReloadStatus(
                applied: 3,
                partlyApplied: 1,
                failed: 1,
                last: new ReloadAttempt(ReloadOutcome::PartlyApplied, self::createInstantAt($now - 30.0), null, ['mqtt', 'log_level']),
            ),
            schedules: [new ScheduleStatus('demo', 0, 'w0:2', 'every 5m', self::createInstantAt($now + 240.0), self::createInstantAt($now - 60.0))],
            mqtt: new MqttStatus(
                ConnectionPhase::Reconnecting,
                self::createInstantAt($now - 20.0),
                2,
                Duration::seconds(4),
                'connection refused',
                [new MqttDropCount(MqttDropReason::Disconnected, 3), new MqttDropCount(MqttDropReason::BufferFull, 0)],
                5,
            ),
            generated: $generated,
        );

    }

    private static function createDriftedCode(): GeneratedCodeStatus
    {
        return new GeneratedCodeStatus(GeneratedFormat::VERSION, GeneratedFormat::VERSION, ['light.landing', 'switch.fan'], ['light.old'], self::createInstantAt(self::NOW - 30.0));
    }

    private static function createInstantAt(float $epochSeconds): Instant
    {
        return Instant::fromEpochMicroseconds((int) round($epochSeconds * 1_000_000));
    }
}
