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
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Store\StoreHealth;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(StatusRenderer::class)]
#[CoversClass(StatusFormatter::class)]
// Set UPDATE_GOLDEN=1 to rewrite tests/Fixtures/Control/status.txt from the snapshot below.
final class StatusRendererTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../Fixtures/Control/status.txt';

    private const float NOW = 1700003600.0;

    public function testEverySectionIsRenderedFromTheSnapshot(): void
    {
        $snapshot = self::createSnapshot(new ComponentStatus(ComponentState::Active, self::createInstantAt(self::NOW - 30.0), 'default', '0.9.0', 1));

        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, $snapshot);
        $text = $output->fetch();

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $text);
        }

        self::assertStringEqualsFile(self::GOLDEN, $text);
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
    }

    #[DataProvider('provideComponentRows')]
    public function testComponentRowExplainsState(ComponentStatus $component, string $expected): void
    {
        $output = new BufferedOutput();
        new StatusRenderer(new StatusFormatter())->render($output, self::createSnapshot($component));

        self::assertStringContainsString('component      ' . $expected, $output->fetch());
    }

    /** @return iterable<string, array{ComponentStatus, string}> */
    public static function provideComponentRows(): iterable
    {
        $since = self::createInstantAt(self::NOW - 120.0);

        yield 'missing' => [new ComponentStatus(ComponentState::Missing, $since, 'default'), 'missing since 2m 00s ago, install the stewart integration in Home Assistant'];
        yield 'protocol mismatch' => [new ComponentStatus(ComponentState::ProtocolMismatch, $since, 'default', '0.10.0', 2), 'protocol mismatch since 2m 00s ago, version 0.10.0, protocol 2 where Stewart speaks 1'];
        yield 'replaced' => [new ComponentStatus(ComponentState::Replaced, $since, 'upstairs', '0.9.0', 1), 'replaced since 2m 00s ago, version 0.9.0, another Stewart uses expose.instance "upstairs"'];
    }

    private static function createSnapshot(?ComponentStatus $component): RuntimeSnapshot
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
                    counters: new AppCounters(delivered: 12, subscriptionDropped: 1, scheduleRuns: 3, publishes: 2, failures: 1, suppressed: 4),
                    serviceCalls: [
                        new ServiceCallStats(ServiceCallOutcome::Succeeded, 4, new LatencyHistogram([5, 10], [1, 3], 4, Duration::milliseconds(40))),
                        new ServiceCallStats(ServiceCallOutcome::TimedOut, 1, new LatencyHistogram([5, 10], [0, 0], 1, Duration::seconds(30))),
                    ],
                    lastFailure: new FailureReport(AppFailurePhase::Handler, 'RuntimeException', 'lamp offline', null, 'unreachable', self::createInstantAt($now - 60.0)),
                ),
                new AppStatus('echo', 'Echo', 1, AppState::Running, null, new AppPauseStatus(self::createInstantAt($now - 300.0), AppPauseSource::Control), null, 0, 0, new AppCounters(), [], null),
            ],
            subscriptions: [],
            store: new StoreHealth(false, 'connection refused', self::createInstantAt($now - 20.0)),
            deploy: new DeployStatus(
                str_repeat('a', 40),
                self::createInstantAt($now - 15.0),
                2,
                new DeployFailure(str_repeat('b', 40), 'apps/Porch.php failed to load: syntax error', self::createInstantAt($now - 120.0)),
            ),
            component: $component,
        );

    }

    private static function createInstantAt(float $epochSeconds): Instant
    {
        return Instant::fromEpochMicroseconds((int) round($epochSeconds * 1_000_000));
    }
}
