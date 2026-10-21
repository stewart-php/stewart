<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Mqtt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Mqtt\MqttConnectionTracker;
use Stewart\Runtime\Control\Protocol\Status\MqttDropCount;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Model\MqttDropReason;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(MqttConnectionTracker::class)]
#[CoversClass(MqttStatus::class)]
#[CoversClass(MqttDropReason::class)]
final class MqttConnectionTrackerTest extends TestCase
{
    private VirtualClock $clock;

    private RecordingLogger $logger;

    private MqttConnectionTracker $tracker;

    protected function setUp(): void
    {
        $this->clock = new VirtualClock();
        $this->logger = new RecordingLogger();
        $this->tracker = new MqttConnectionTracker($this->clock, $this->logger);
    }

    public function testFailedFirstAttemptStaysConnectingWithItsReason(): void
    {
        $this->tracker->connectionLost('connection refused');

        $status = $this->tracker->buildMqttStatus(0);

        self::assertSame([ConnectionPhase::Connecting, null, 'connection refused'], [$status->phase, $status->since, $status->lastError]);
    }

    public function testReconnectCountsTheOutage(): void
    {
        $this->tracker->connected();
        $this->tracker->connectionLost('keepalive timed out');
        $lostAt = $this->clock->getNow();
        $this->advanceBy(Duration::seconds(2));
        $this->tracker->connectionLost('connection refused');

        $reconnecting = $this->tracker->buildMqttStatus(3);

        self::assertSame([ConnectionPhase::Reconnecting, 'connection refused', 3], [$reconnecting->phase, $reconnecting->lastError, $reconnecting->outboundQueued]);
        self::assertEquals($lostAt, $reconnecting->since, 'A failed retry does not restart the outage.');

        $this->advanceBy(Duration::seconds(2));
        $this->tracker->connected();
        $restored = $this->tracker->buildMqttStatus(0);

        self::assertSame([ConnectionPhase::Connected, 1, null], [$restored->phase, $restored->reconnects, $restored->lastError]);
        self::assertEquals(Duration::seconds(4), $restored->lastOutage);
    }

    public function testDropsAreCountedPerReasonAndWarnedSampled(): void
    {
        for ($drop = 1; $drop <= 100; ++$drop) {
            $this->tracker->messageDropped(MqttDropReason::Disconnected, 'out/light');
        }

        $this->tracker->messageDropped(MqttDropReason::BufferFull, 'out/first');

        self::assertCount(3, $this->logger->listMessagesAt('warning'), 'The first and the 100th disconnected drop, and the first full buffer.');
        self::assertSame(100, $this->logger->records->filter(static fn(RecordedLog $record): bool => $record->message === MqttDropReason::Disconnected->describeDrop())->getLast()?->context['dropped'] ?? null);
        self::assertEquals(
            [new MqttDropCount(MqttDropReason::Disconnected, 100), new MqttDropCount(MqttDropReason::SendFailed, 0), new MqttDropCount(MqttDropReason::BufferFull, 1), new MqttDropCount(MqttDropReason::GaveUp, 0)],
            $this->tracker->buildMqttStatus(0)->dropped,
        );
        self::assertSame(101, $this->tracker->buildMqttStatus(0)->countDropped());
    }

    public function testMessageTheServerNeverAcknowledgedIsAnError(): void
    {
        $this->tracker->messageDropped(MqttDropReason::GaveUp, 'out/poison');
        $this->tracker->messageDropped(MqttDropReason::GaveUp, 'out/poison');

        self::assertCount(2, $this->logger->listMessagesAt('error'));
    }

    private function advanceBy(Duration $duration): void
    {
        $this->clock->moveTo($this->clock->getMonotonicTime()->plus($duration));
    }
}
