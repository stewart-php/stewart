<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\HaContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;

#[CoversClass(RecordingHaContext::class)]
final class RecordingHaContextTest extends TestCase
{
    use AssertsReason;

    public function testPublishRejectsNonFinitePayload(): void
    {
        $this->assertThrowsReason(TopicError::PayloadInvalid, fn() => new RecordingHaContext()->publish('hall.motion', ['level' => NAN]));
    }

    public function testStateRejectsInvalidEntityId(): void
    {
        $this->assertThrowsReason(IdentifierError::EntityIdInvalid, fn() => new RecordingHaContext()->getState('Not An Id'));
    }

    public function testRequireStateRejectsInvalidEntityId(): void
    {
        $this->assertThrowsReason(IdentifierError::EntityIdInvalid, fn() => new RecordingHaContext()->requireState('Not An Id'));
    }

    public function testPublishRecordsValidatedPayload(): void
    {
        $context = new RecordingHaContext();

        $context->publish('hall.motion', ['on' => true]);

        self::assertSame(['on' => true], $context->published->getFirst()?->payload);
    }
}
