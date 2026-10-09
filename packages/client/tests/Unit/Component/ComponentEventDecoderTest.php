<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\SessionReplaced;

#[CoversClass(ComponentEventDecoder::class)]
final class ComponentEventDecoderTest extends TestCase
{
    public function testSessionReplacedIsDecoded(): void
    {
        self::assertInstanceOf(SessionReplaced::class, new ComponentEventDecoder()->decodeSessionEvent(['type' => 'session_replaced']));
    }

    public function testUnknownTypeIsSkipped(): void
    {
        self::assertNull(new ComponentEventDecoder()->decodeSessionEvent(['type' => 'sentence']));
        self::assertNull(new ComponentEventDecoder()->decodeSessionEvent([]));
    }
}
