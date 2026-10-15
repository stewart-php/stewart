<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Connection;

use Amp\ByteStream\BufferedReader;
use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Closure;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Time\Timers;
use Stewart\Mqtt\Exception\MqttClientError;
use Stewart\Mqtt\Exception\MqttClientException;
use Stewart\Mqtt\Packet\ConnAckPacket;
use Stewart\Mqtt\Packet\ConnectReturnCode;
use Stewart\Mqtt\Packet\PacketEncoder;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\Socket\connect;
use function Amp\Socket\connectTls;

final readonly class MqttConnector
{
    public function __construct(
        private PacketReader $packetReader,
        private PacketEncoder $encoder,
        private Timers $timers,
        private Deadlines $deadlines,
    ) {}

    /**
     * @param Closure(MqttMessage): void $onMessage
     * @throws MqttClientException|Throwable
     */
    public function openConnection(MqttConfig $config, Closure $onMessage, Cancellation $stop): MqttConnection
    {
        $timeout = $this->deadlines->timeout($config->connectTimeout);
        $socket = $this->openSocket($config, new CompositeCancellation($stop, $timeout));
        $reader = new BufferedReader($socket);

        try {
            // Only the timeout ends the handshake: abandoning it after CONNECT would make the server publish the will.
            $this->acceptHandshake($socket, $reader, $this->encodeConnect($config), $timeout);
        } catch (Throwable $e) {
            $socket->close();

            throw $e;
        }

        $connection = new MqttConnection($socket, $reader, $this->packetReader, $this->encoder, $this->timers, $config->keepalive, $onMessage);
        $connection->startReading();

        return $connection;
    }

    /** @throws MqttClientException|Throwable */
    private function acceptHandshake(Socket $socket, BufferedReader $reader, string $connect, Cancellation $timeout): void
    {
        $socket->write($connect);
        $answer = $this->packetReader->readPacket($reader, $timeout);

        if (!$answer instanceof ConnAckPacket) {
            throw MqttClientException::packetUnexpected($answer->getPacketType()->name);
        }

        if ($answer->returnCode !== ConnectReturnCode::Accepted) {
            throw MqttClientException::createForReason(MqttClientError::ConnectionRefused, refusal: $answer->returnCode->describeRefusal());
        }
    }

    private function encodeConnect(MqttConfig $config): string
    {
        return $this->encoder->encodeConnect(
            clientId: $config->clientId,
            keepalive: $config->keepalive,
            username: $config->serverUrl->username,
            password: $config->serverUrl->revealPassword(),
            will: $config->will,
        );
    }

    /** @throws Throwable */
    private function openSocket(MqttConfig $config, Cancellation $deadline): Socket
    {
        $context = new ConnectContext()->withTcpNoDelay();

        if (!$config->serverUrl->usesTls) {
            return connect($config->serverUrl->toSocketUri(), $context, $deadline);
        }

        return connectTls($config->serverUrl->toSocketUri(), $context->withTlsContext(new ClientTlsContext($config->serverUrl->host)), $deadline);
    }
}
