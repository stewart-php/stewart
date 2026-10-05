<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Time\Instant;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(EventDecoder::class)]
#[CoversClass(EntityStateDecoder::class)]
final class EventDecoderTest extends TestCase
{
    private RecordingLogger $logger;

    private EntityStateDecoder $states;

    private EventDecoder $decoder;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->states = new EntityStateDecoder($this->logger);
        $this->decoder = new EventDecoder($this->states);
    }

    public function testDecodesEvent(): void
    {
        $event = $this->decoder->decodeEvent([
            'event_type' => 'zha_event',
            'data' => ['device_ieee' => '00:11', 'command' => 'toggle'],
            'origin' => 'REMOTE',
            'time_fired' => '2026-09-21T08:30:00.123456+00:00',
            'context' => ['id' => 'ctx1', 'user_id' => 'u1'],
        ]);

        self::assertNotNull($event);
        self::assertSame('zha_event', $event->type);
        self::assertSame('toggle', $event->getValue('command'));
        self::assertSame(EventOrigin::Remote, $event->origin);
        self::assertSame('2026-09-21T08:30:00.123456Z', $event->firedAt?->toIso8601());
        self::assertSame('ctx1', $event->context?->id);
    }

    public function testDecodesTriggerEvent(): void
    {
        $event = $this->decoder->decodeTriggerEvent([
            'variables' => ['trigger' => ['platform' => 'sun', 'event' => 'sunset', 'idx' => '0']],
            'context' => ['id' => 'ctx1'],
        ]);

        self::assertNotNull($event);
        self::assertSame('sun', $event->getPlatform());
        self::assertSame('0', $event->getTriggerId());
        self::assertSame('ctx1', $event->context?->id);
        self::assertNull($event->firedAt);
    }

    public function testRejectsTriggerEventWithoutTriggerVariables(): void
    {
        self::assertNull($this->decoder->decodeTriggerEvent(['context' => ['id' => 'ctx1']]));
        self::assertNull($this->decoder->decodeTriggerEvent(['variables' => ['trigger' => 'sun']]));
    }

    public function testRejectsEventWithoutType(): void
    {
        self::assertNull($this->decoder->decodeEvent(['data' => ['a' => 1]]));
        self::assertNull($this->decoder->decodeEvent(['event_type' => '']));
        self::assertNull($this->decoder->decodeEvent(['event_type' => 42]));
    }

    public function testMissingPartsFallBack(): void
    {
        $event = $this->decoder->decodeEvent(['event_type' => 'stewart_demo', 'data' => 'not an array', 'origin' => 'MARS']);

        self::assertNotNull($event);
        self::assertSame([], $event->data);
        self::assertSame(EventOrigin::Local, $event->origin);
        self::assertNull($event->firedAt);
        self::assertNull($event->context);
        self::assertTrue($this->logger->records->isEmpty());
    }

    public function testStateChangeParsesTimestamps(): void
    {
        $change = $this->decoder->decodeStateChange([
            'event_type' => 'state_changed',
            'time_fired' => '2026-09-21T08:30:01+00:00',
            'data' => [
                'entity_id' => 'light.hall',
                'old_state' => ['entity_id' => 'light.hall', 'state' => 'off'],
                'new_state' => [
                    'entity_id' => 'light.hall',
                    'state' => 'on',
                    'last_changed' => '2026-09-21T08:30:00.5+00:00',
                    'last_updated' => '2026-09-21T10:30:00.5+02:00',
                ],
            ],
        ]);

        self::assertNotNull($change);
        self::assertSame('light.hall', (string) $change->entityId);
        self::assertTrue($change->changedTo('on'));
        self::assertEquals(Instant::fromIso('2026-09-21T08:30:01Z'), $change->firedAt);
        self::assertEquals(Instant::fromIso('2026-09-21T08:30:00.5Z'), $change->to?->lastChangedAt);
        self::assertEquals($change->to?->lastChangedAt, $change->to?->lastUpdatedAt);
    }

    public function testMalformedTimestampWarnsOncePerField(): void
    {
        $broken = ['entity_id' => 'sensor.odd', 'state' => '1', 'last_changed' => 'yesterday', 'last_updated' => 'yesterday'];

        foreach ([1, 2, 3] as $_) {
            $state = $this->states->decodeEntityStateOrSkip($broken);

            self::assertNotNull($state);
            self::assertNull($state->lastChangedAt);
            self::assertNull($state->lastUpdatedAt);
            self::assertSame('1', $state->state);
        }

        $warnings = $this->logger->records->filter(static fn($record): bool => $record->level === 'warning')->listValues();

        self::assertCount(2, $warnings);
        self::assertSame(['source' => 'sensor.odd', 'field' => 'last_changed', 'value' => 'yesterday'], $warnings[0]->context);
        self::assertSame('last_updated', $warnings[1]->context['field']);
    }

    public function testNonStringTimestampWarns(): void
    {
        $event = $this->decoder->decodeEvent(['event_type' => 'zha_event', 'time_fired' => 1_700_000_000]);

        self::assertNull($event?->firedAt);
        self::assertSame(['warning'], $this->logger->records->mapToList(static fn($record): string => $record->level));
    }
}
