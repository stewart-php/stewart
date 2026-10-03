<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final class PacketBytes
{
    public const int MAX_REMAINING_LENGTH = 268_435_455;

    private const int LENGTH_DIGIT_BASE = 128;

    private function __construct() {}

    public static function assemblePacket(PacketType $type, int $flags, string $body): string
    {
        return pack('C', ($type->value << 4) | $flags) . self::encodeRemainingLength(\strlen($body)) . $body;
    }

    public static function encodeString(string $value): string
    {
        return pack('n', \strlen($value)) . $value;
    }

    public static function encodeUint16(int $value): string
    {
        return pack('n', $value);
    }

    public static function encodeRemainingLength(int $length): string
    {
        $encoded = '';

        do {
            $digit = $length % self::LENGTH_DIGIT_BASE;
            $length = intdiv($length, self::LENGTH_DIGIT_BASE);
            $encoded .= pack('C', $length > 0 ? $digit | self::LENGTH_DIGIT_BASE : $digit);
        } while ($length > 0);

        return $encoded;
    }
}
