<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Control\Protocol\Status\MqttDropCount;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\MqttDropReason;

final class MqttConnectionTracker implements MqttLinkListener
{
    private ConnectionPhase $phase = ConnectionPhase::Connecting;

    private ?Instant $since = null;

    private int $reconnects = 0;

    private ?MonotonicTime $lostAt = null;

    private ?Duration $lastOutage = null;

    private ?string $lastError = null;

    /** @var array<string, int> */
    private array $dropsByReason = [];

    private readonly EveryNthOccurrence $dropReports;

    public function __construct(
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->dropReports = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function connected(): void
    {
        if ($this->lostAt !== null) {
            $this->lastOutage = $this->clock->getMonotonicTime()->elapsedSince($this->lostAt);
            $this->lostAt = null;
            ++$this->reconnects;
        }

        $this->phase = ConnectionPhase::Connected;
        $this->since = $this->clock->getNow();
        $this->lastError = null;
    }

    public function connectionLost(string $reason): void
    {
        $this->lastError = $reason;

        if ($this->phase === ConnectionPhase::Connected) {
            $this->phase = ConnectionPhase::Reconnecting;
            $this->since = $this->clock->getNow();
            $this->lostAt = $this->clock->getMonotonicTime();
        }
    }

    // Status and metrics hold the exact counts, so a long outage logs its drops sampled rather than one by one.
    public function messageDropped(MqttDropReason $reason, string $topic): void
    {
        $dropped = $this->dropsByReason[$reason->value] = ($this->dropsByReason[$reason->value] ?? 0) + 1;
        $context = ['topic' => $topic, 'dropped' => $dropped];

        if ($reason === MqttDropReason::GaveUp) {
            $this->logger->error($reason->describeDrop(), $context);
        } elseif ($this->dropReports->includesOccurrence($dropped)) {
            $this->logger->warning($reason->describeDrop(), $context);
        }
    }

    public function buildMqttStatus(int $outboundQueued): MqttStatus
    {
        return new MqttStatus(
            phase: $this->phase,
            since: $this->since,
            reconnects: $this->reconnects,
            lastOutage: $this->lastOutage,
            lastError: $this->lastError,
            dropped: array_map(fn(MqttDropReason $reason): MqttDropCount => new MqttDropCount($reason, $this->dropsByReason[$reason->value] ?? 0), MqttDropReason::cases()),
            outboundQueued: $outboundQueued,
        );
    }
}
