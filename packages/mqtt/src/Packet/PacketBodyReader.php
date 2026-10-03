<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Mqtt\Exception\MqttClientException;

final class PacketBodyReader
{
    private int $offset = 0;

    public function __construct(
        private readonly PacketType $type,
        private readonly string $body,
    ) {}

    /** @throws MqttClientException */
    public function readUint8(): int
    {
        return \ord($this->readBytes(1));
    }

    /** @throws MqttClientException */
    public function readUint16(): int
    {
        $unpacked = unpack('n', $this->readBytes(2));

        return \is_array($unpacked) && \is_int($unpacked[1] ?? null) ? $unpacked[1] : throw $this->createMalformedException();
    }

    /** @throws MqttClientException */
    public function readString(): string
    {
        return $this->readBytes($this->readUint16());
    }

    public function readRemainder(): string
    {
        $remainder = substr($this->body, $this->offset);
        $this->offset = \strlen($this->body);

        return $remainder;
    }

    /** @throws MqttClientException */
    public function assertFullyRead(): void
    {
        if ($this->offset !== \strlen($this->body)) {
            throw $this->createMalformedException();
        }
    }

    public function createMalformedException(): MqttClientException
    {
        return MqttClientException::packetMalformed($this->type->name);
    }

    /** @throws MqttClientException */
    private function readBytes(int $length): string
    {
        if ($this->offset + $length > \strlen($this->body)) {
            throw $this->createMalformedException();
        }

        $bytes = substr($this->body, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
