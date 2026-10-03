<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Connection;

use Amp\ByteStream\BufferedReader;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\Socket;
use Closure;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;
use Stewart\Mqtt\Exception\MqttClientException;
use Stewart\Mqtt\Packet\ConnAckPacket;
use Stewart\Mqtt\Packet\DisconnectPacket;
use Stewart\Mqtt\Packet\InboundPacketHandler;
use Stewart\Mqtt\Packet\OutboundPacket;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Mqtt\Packet\PingReqPacket;
use Stewart\Mqtt\Packet\PingRespPacket;
use Stewart\Mqtt\Packet\PubAckPacket;
use Stewart\Mqtt\Packet\PublishPacket;
use Stewart\Mqtt\Packet\SubAckPacket;
use Stewart\Mqtt\Packet\SubscribePacket;
use Stewart\Mqtt\Packet\UnsubAckPacket;
use Stewart\Mqtt\Packet\UnsubscribePacket;
use Throwable;

use function Amp\async;

final class MqttConnection implements InboundPacketHandler
{
    private const int HIGHEST_PACKET_ID = 65535;

    private const MqttQos SUBSCRIPTION_QOS = MqttQos::AtLeastOnce;

    /** @var array<int, DeferredFuture<null>> */
    private array $pendingAcks = [];

    /** @var array<int, DeferredFuture<MqttQos|null>> */
    private array $pendingSubAcks = [];

    private int $lastPacketId = 0;

    private bool $awaitingPingResponse = false;

    private ?TimerHandle $keepaliveTimer = null;

    /** @var DeferredFuture<MqttClientException|null> */
    private readonly DeferredFuture $closed;

    /** @param Closure(MqttMessage): void $onMessage */
    public function __construct(
        private readonly Socket $socket,
        private readonly BufferedReader $bufferedReader,
        private readonly PacketReader $packetReader,
        private readonly Timers $timers,
        private readonly Duration $keepalive,
        private readonly Closure $onMessage,
    ) {
        $this->closed = new DeferredFuture();
    }

    public function startReading(): void
    {
        async($this->readUntilClosed(...));
        $this->scheduleKeepalive();
    }

    /**
     * @return Future<null>
     * @throws MqttClientException
     */
    public function publishMessage(MqttMessage $message): Future
    {
        if ($message->qos === MqttQos::AtMostOnce) {
            $this->writePacket(new PublishPacket($message));

            return Future::complete();
        }

        $packetId = $this->allocatePacketId();
        $this->pendingAcks[$packetId] = new DeferredFuture();
        $this->writePacket(new PublishPacket($message, $packetId));

        return $this->pendingAcks[$packetId]->getFuture();
    }

    /**
     * @return Future<MqttQos|null>
     * @throws MqttClientException
     */
    public function subscribeFilter(string $topicFilter): Future
    {
        $packetId = $this->allocatePacketId();
        $this->pendingSubAcks[$packetId] = new DeferredFuture();
        $this->writePacket(new SubscribePacket($packetId, $topicFilter, self::SUBSCRIPTION_QOS));

        return $this->pendingSubAcks[$packetId]->getFuture();
    }

    /**
     * @return Future<null>
     * @throws MqttClientException
     */
    public function unsubscribeFilter(string $topicFilter): Future
    {
        $packetId = $this->allocatePacketId();
        $this->pendingAcks[$packetId] = new DeferredFuture();
        $this->writePacket(new UnsubscribePacket($packetId, $topicFilter));

        return $this->pendingAcks[$packetId]->getFuture();
    }

    public function disconnect(): void
    {
        if ($this->closed->isComplete()) {
            return;
        }

        try {
            $this->socket->write(new DisconnectPacket()->encodePacket());
        } catch (Throwable) {
            // The socket is closing either way; the server then treats the client as gone.
        }

        $this->finishClosing(null);
    }

    public function awaitClosed(): ?MqttClientException
    {
        return $this->closed->getFuture()->await();
    }

    public function handleConnAck(ConnAckPacket $packet): void
    {
        throw MqttClientException::packetUnexpected($packet->getPacketType()->name);
    }

    public function handlePublish(PublishPacket $packet): void
    {
        if ($packet->packetId !== null) {
            $this->writePacket(new PubAckPacket($packet->packetId));
        }

        ($this->onMessage)($packet->message);
    }

    public function handlePubAck(PubAckPacket $packet): void
    {
        $this->settleAck($packet);
    }

    public function handleSubAck(SubAckPacket $packet): void
    {
        $pending = $this->pendingSubAcks[$packet->packetId] ?? throw MqttClientException::packetUnexpected($packet->getPacketType()->name);
        unset($this->pendingSubAcks[$packet->packetId]);
        $pending->complete($packet->grantedQos);
    }

    public function handleUnsubAck(UnsubAckPacket $packet): void
    {
        $this->settleAck($packet);
    }

    public function handlePingResp(PingRespPacket $packet): void
    {
        $this->awaitingPingResponse = false;
    }

    private function readUntilClosed(): void
    {
        try {
            while (!$this->closed->isComplete()) {
                $this->packetReader->readPacket($this->bufferedReader)->applyTo($this);
            }
        } catch (Throwable $e) {
            $this->finishClosing($e instanceof MqttClientException ? $e : MqttClientException::connectionLost($e));
        }
    }

    private function scheduleKeepalive(): void
    {
        $this->keepaliveTimer = $this->timers->startTimer($this->keepalive, function (): void {
            if ($this->closed->isComplete()) {
                return;
            }

            if ($this->awaitingPingResponse) {
                $this->finishClosing(MqttClientException::keepaliveTimedOut($this->keepalive));

                return;
            }

            $this->awaitingPingResponse = true;
            async(function (): void {
                try {
                    $this->writePacket(new PingReqPacket());
                } catch (MqttClientException) {
                }
            });
            $this->scheduleKeepalive();
        });
    }

    /** @throws MqttClientException */
    private function writePacket(OutboundPacket $packet): void
    {
        if ($this->closed->isComplete()) {
            throw MqttClientException::connectionClosed();
        }

        try {
            $this->socket->write($packet->encodePacket());
        } catch (Throwable $e) {
            $lost = MqttClientException::connectionLost($e);
            $this->finishClosing($lost);

            throw $lost;
        }
    }

    /** @throws MqttClientException */
    private function settleAck(PubAckPacket|UnsubAckPacket $ack): void
    {
        $pending = $this->pendingAcks[$ack->packetId] ?? throw MqttClientException::packetUnexpected($ack->getPacketType()->name);
        unset($this->pendingAcks[$ack->packetId]);
        $pending->complete();
    }

    /** @throws MqttClientException */
    private function allocatePacketId(): int
    {
        for ($attempt = 0; $attempt < self::HIGHEST_PACKET_ID; ++$attempt) {
            $this->lastPacketId = $this->lastPacketId % self::HIGHEST_PACKET_ID + 1;

            if (!isset($this->pendingAcks[$this->lastPacketId]) && !isset($this->pendingSubAcks[$this->lastPacketId])) {
                return $this->lastPacketId;
            }
        }

        throw MqttClientException::packetIdsExhausted();
    }

    private function finishClosing(?MqttClientException $reason): void
    {
        if ($this->closed->isComplete()) {
            return;
        }

        $this->keepaliveTimer?->cancel();
        $this->socket->close();
        $failure = $reason ?? MqttClientException::connectionClosed();

        foreach ([...$this->pendingAcks, ...$this->pendingSubAcks] as $pending) {
            $pending->error($failure);
            $pending->getFuture()->ignore();
        }

        $this->pendingAcks = [];
        $this->pendingSubAcks = [];
        $this->closed->complete($reason);
    }
}
