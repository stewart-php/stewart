<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Mqtt\OutboundMqttQueue;

#[CoversClass(OutboundMqttQueue::class)]
final class OutboundMqttQueueTest extends TestCase
{
    public function testFullQueueDropsOldestMessage(): void
    {
        $queue = new OutboundMqttQueue(2);
        $queue->enqueueMessage(new MqttMessage('a', '1'));
        $queue->enqueueMessage(new MqttMessage('b', '2'));

        self::assertTrue($queue->isFull());

        $queue->enqueueMessage(new MqttMessage('c', '3'));

        self::assertSame(['b', 'c'], self::listPendingTopics($queue));
    }

    public function testAcknowledgedMessageLeavesTheQueue(): void
    {
        $queue = new OutboundMqttQueue(5);
        $first = $queue->enqueueMessage(new MqttMessage('a', '1'));
        $queue->enqueueMessage(new MqttMessage('b', '2'));

        $queue->acknowledgeMessage($first);

        self::assertSame(['b'], self::listPendingTopics($queue));
        self::assertSame(1, $queue->countPending());
    }

    public function testZeroCapacityHoldsNothing(): void
    {
        self::assertFalse(new OutboundMqttQueue(0)->canHoldMessages());
    }

    /** @return list<string> */
    private static function listPendingTopics(OutboundMqttQueue $queue): array
    {
        $topics = [];
        $queue->replayPendingMessages(static function (int $sequence, MqttMessage $message) use (&$topics): void {
            $topics[] = $message->topic;
        });

        return $topics;
    }
}
