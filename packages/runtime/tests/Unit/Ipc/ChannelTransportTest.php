<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Ipc\ChannelTransport;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Testing\Exception\AssertsReason;

use function Amp\Sync\createChannelPair;

#[CoversClass(ChannelTransport::class)]
final class ChannelTransportTest extends TestCase
{
    use AssertsReason;

    public function testMessageCrossesAsAJsonFrame(): void
    {
        [$left, $right] = createChannelPair(1);
        $sender = new ChannelTransport($left, IpcCodec::createForWorkerBootstrap());
        $receiver = new ChannelTransport($right, IpcCodec::createForWorkerBootstrap());

        $sender->send(new Unsubscribe(new SubscriptionId('w0:3')));

        self::assertEquals(new Unsubscribe(new SubscriptionId('w0:3')), $receiver->receive());
    }

    public function testFrameThatIsNotJsonIsUndecodableNotFatal(): void
    {
        [$left, $right] = createChannelPair(1);
        $receiver = new ChannelTransport($right, IpcCodec::createForWorkerBootstrap());

        $left->send('not json');

        $this->assertThrowsReason(TransportError::UndecodableFrame, fn() => $receiver->receive());
    }

    public function testClosedTransportRefusesToSend(): void
    {
        [$left] = createChannelPair(1);
        $transport = new ChannelTransport($left, IpcCodec::createForWorkerBootstrap());
        $transport->close();

        $this->assertThrowsReason(TransportError::Closed, fn() => $transport->send(new Unsubscribe(new SubscriptionId('w0:3'))));
    }
}
