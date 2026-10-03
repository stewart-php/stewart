<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Amp\ByteStream\BufferedReader;
use Amp\Cancellation;
use Stewart\Mqtt\Exception\MqttClientException;
use Throwable;

final readonly class PacketReader
{
    private const int MAX_LENGTH_DIGITS = 4;

    private const int LENGTH_DIGIT_BASE = 128;

    private const int MAX_INBOUND_PACKET_BYTES = 16 * 1024 * 1024;

    public function __construct(private PacketDecoder $decoder) {}

    /** @throws MqttClientException|Throwable */
    public function readPacket(BufferedReader $reader, ?Cancellation $cancellation = null): InboundPacket
    {
        $fixedHeader = \ord($reader->readLength(1, $cancellation));
        $remainingLength = $this->readRemainingLength($reader, $cancellation);

        if ($remainingLength > self::MAX_INBOUND_PACKET_BYTES) {
            throw MqttClientException::packetMalformed($remainingLength . '-byte');
        }

        return $this->decoder->decodePacket($fixedHeader, $remainingLength > 0 ? $reader->readLength($remainingLength, $cancellation) : '');
    }

    /** @throws MqttClientException|Throwable */
    private function readRemainingLength(BufferedReader $reader, ?Cancellation $cancellation): int
    {
        $length = 0;
        $multiplier = 1;

        for ($digits = 0; $digits < self::MAX_LENGTH_DIGITS; ++$digits) {
            $digit = \ord($reader->readLength(1, $cancellation));
            $length += ($digit % self::LENGTH_DIGIT_BASE) * $multiplier;

            if ($digit < self::LENGTH_DIGIT_BASE) {
                return $length;
            }

            $multiplier *= self::LENGTH_DIGIT_BASE;
        }

        throw MqttClientException::packetMalformed('remaining length');
    }
}
