<?php

declare(strict_types=1);

namespace Stewart\Mqtt;

use Closure;
use Stewart\Contracts\Mqtt\MqttMessage;

// QoS 1 messages stay here until the server acknowledges them, so a reconnect can send them again.
final class OutboundMqttQueue
{
    // A message the server keeps dropping the connection over would otherwise be replayed forever.
    private const int MAX_SEND_ATTEMPTS = 5;

    /** @var array<int, PendingMqttMessage> */
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
        $this->pendingBySequence[$sequence] = new PendingMqttMessage($message);

        if (\count($this->pendingBySequence) > $this->outboundCapacity) {
            $this->pendingBySequence = \array_slice($this->pendingBySequence, -$this->outboundCapacity, preserve_keys: true);
        }

        return $sequence;
    }

    public function recordSendAttempt(int $sequence): void
    {
        ($this->pendingBySequence[$sequence] ?? null)?->recordSendAttempt();
    }

    public function acknowledgeMessage(int $sequence): void
    {
        unset($this->pendingBySequence[$sequence]);
    }

    /**
     * @param Closure(int, MqttMessage): void $send
     * @param Closure(MqttMessage, int): void $onGivenUp
     */
    public function replayPendingMessages(Closure $send, Closure $onGivenUp): void
    {
        foreach ($this->pendingBySequence as $sequence => $pending) {
            if ($pending->sendAttempts >= self::MAX_SEND_ATTEMPTS) {
                unset($this->pendingBySequence[$sequence]);
                $onGivenUp($pending->message, $pending->sendAttempts);

                continue;
            }

            $send($sequence, $pending->message);
        }
    }

    public function findOldestMessage(): ?MqttMessage
    {
        $oldest = array_key_first($this->pendingBySequence);

        return $oldest === null ? null : $this->pendingBySequence[$oldest]->message;
    }

    public function countPending(): int
    {
        return \count($this->pendingBySequence);
    }
}
