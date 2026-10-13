<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Mqtt\OutboundMqttQueue;
use Stewart\Mqtt\PendingMqttMessage;

#[CoversClass(OutboundMqttQueue::class)]
#[CoversClass(PendingMqttMessage::class)]
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

    public function testMessageSentFiveTimesIsGivenUpInsteadOfReplayed(): void
    {
        $queue = new OutboundMqttQueue(5);
        $poison = $queue->enqueueMessage(new MqttMessage('poison', '1'));
        $queue->enqueueMessage(new MqttMessage('fine', '2'));
        self::recordSendAttempts($queue, $poison, 5);

        $givenUp = [];
        $replayed = self::listPendingTopics($queue, static function (MqttMessage $message, int $sendAttempts) use (&$givenUp): void {
            $givenUp[] = [$message->topic, $sendAttempts];
        });

        self::assertSame(['fine'], $replayed);
        self::assertSame([['poison', 5]], $givenUp);
        self::assertSame(1, $queue->countPending());
    }

    public function testMessageBelowTheSendLimitIsReplayed(): void
    {
        $queue = new OutboundMqttQueue(5);
        self::recordSendAttempts($queue, $queue->enqueueMessage(new MqttMessage('a', '1')), 4);

        self::assertSame(['a'], self::listPendingTopics($queue));
    }

    public function testSendAttemptForAnAcknowledgedMessageIsIgnored(): void
    {
        $queue = new OutboundMqttQueue(5);
        $sequence = $queue->enqueueMessage(new MqttMessage('a', '1'));
        $queue->acknowledgeMessage($sequence);

        $queue->recordSendAttempt($sequence);

        self::assertSame(0, $queue->countPending());
    }

    private static function recordSendAttempts(OutboundMqttQueue $queue, int $sequence, int $attempts): void
    {
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $queue->recordSendAttempt($sequence);
        }
    }

    /**
     * @param ?Closure(MqttMessage, int): void $onGivenUp
     * @return list<string>
     */
    private static function listPendingTopics(OutboundMqttQueue $queue, ?Closure $onGivenUp = null): array
    {
        $topics = [];
        $queue->replayPendingMessages(
            static function (int $sequence, MqttMessage $message) use (&$topics): void {
                $topics[] = $message->topic;
            },
            $onGivenUp ?? static function (): void {},
        );

        return $topics;
    }
}
