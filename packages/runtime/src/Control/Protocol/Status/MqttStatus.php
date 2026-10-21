<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Lifecycle\ConnectionPhase;

final readonly class MqttStatus
{
    /** @param list<MqttDropCount> $dropped */
    public function __construct(
        public ConnectionPhase $phase,
        public ?Instant $since,
        public int $reconnects,
        public ?Duration $lastOutage,
        public ?string $lastError,
        #[ListOf(MqttDropCount::class)]
        public array $dropped,
        public int $outboundQueued,
    ) {}

    public function countDropped(): int
    {
        return array_sum(array_map(static fn(MqttDropCount $drops): int => $drops->count, $this->dropped));
    }
}
