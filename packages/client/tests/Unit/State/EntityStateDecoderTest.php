<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\State\EventContext;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(EntityStateDecoder::class)]
#[CoversClass(EventContext::class)]
final class EntityStateDecoderTest extends TestCase
{
    public function testParsesHomeAssistantsWireShape(): void
    {
        $state = new EntityStateDecoder()->decodeEntityStateOrSkip([
            'entity_id' => 'light.hall_ceiling',
            'state' => 'on',
            'attributes' => ['friendly_name' => 'Hall Ceiling', 'brightness' => 254],
            'last_changed' => '2026-09-09T12:00:00+00:00',
            'context' => ['id' => 'abc123', 'parent_id' => null, 'user_id' => 'u1'],
        ]);

        self::assertNotNull($state);
        self::assertSame('light', $state->getDomain());
        self::assertSame('hall_ceiling', $state->getObjectId());
        self::assertSame('Hall Ceiling', $state->getFriendlyName());
        self::assertSame(254, $state->getAttribute('brightness'));
        self::assertSame('u1', $state->context?->userId);
        self::assertSame('2026-09-09T12:00:00.000000Z', $state->lastChangedAt?->toIso8601());
    }

    public function testMalformedPayloadsDoNotThrow(): void
    {
        $state = new EntityStateDecoder()->decodeEntityStateOrSkip(['entity_id' => 'sensor.x', 'state' => 42, 'attributes' => 'not-an-array']);

        self::assertNotNull($state);
        self::assertSame('42', $state->state);
        self::assertSame([], $state->attributes);
        self::assertNull($state->lastChangedAt);
    }

    public function testInvalidEntityIdIsSkippedWithWarning(): void
    {
        $logger = new RecordingLogger();

        self::assertNull(new EntityStateDecoder($logger)->decodeEntityStateOrSkip(['entity_id' => 'Not An Id', 'state' => 'on']));
        self::assertSame(['Home Assistant sent an entity without a valid entity id; it is skipped'], $logger->listMessagesAt('warning'));
    }
}
