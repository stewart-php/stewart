<?php

declare(strict_types=1);

namespace Stewart\Mqtt;

use Closure;
use Stewart\Contracts\Mqtt\MqttMessage;

// QoS 1 messages stay here until the server acknowledges them, so a reconnect can send them again.
final class OutboundMqttQueue
{
    /** @var array<int, MqttMessage> */
    private array $pendingBySequence = [];

    private int $nextSequence = 0;

    public function __construct(private readonly int $outboundCapacity) {}

    public function canHoldMessages(): bool
    {
        return $this->outboundCapacity > 0;
    }

    public function isFull(): bool
    {
        return \count($this->pendingBySequence) >= $this->outboundCapacity;
    }

    public function enqueueMessage(MqttMessage $message): int
    {
        $sequence = $this->nextSequence++;
        $this->pendingBySequence[$sequence] = $message;

        if (\count($this->pendingBySequence) > $this->outboundCapacity) {
            $this->pendingBySequence = \array_slice($this->pendingBySequence, -$this->outboundCapacity, preserve_keys: true);
        }

        return $sequence;
    }

    public function acknowledgeMessage(int $sequence): void
    {
        unset($this->pendingBySequence[$sequence]);
    }

    /** @param Closure(int, MqttMessage): void $send */
    public function replayPendingMessages(Closure $send): void
    {
        foreach ($this->pendingBySequence as $sequence => $message) {
            $send($sequence, $message);
        }
    }

    public function countPending(): int
    {
        return \count($this->pendingBySequence);
    }
}
