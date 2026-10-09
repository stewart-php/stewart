<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ExposedEntitySnapshot;
use Stewart\Client\Exception\HaClientError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ExposedEntitySnapshot::class)]
final class ExposedEntitySnapshotTest extends TestCase
{
    use AssertsReason;

    public function testUnknownStateIsRead(): void
    {
        $snapshot = ExposedEntitySnapshot::fromUpsertResult(['entity_id' => 'sensor.tank', 'state' => null, 'attributes' => [], 'available' => false]);

        self::assertNull($snapshot->state);
        self::assertFalse($snapshot->available);
    }

    public function testResultWithoutEntityIdIsProtocolViolation(): void
    {
        $this->assertThrowsReason(
            HaClientError::ProtocolViolation,
            static fn() => ExposedEntitySnapshot::fromUpsertResult(['state' => 1, 'attributes' => [], 'available' => true]),
        );
    }

    public function testMalformedEntityIdIsProtocolViolation(): void
    {
        $this->assertThrowsReason(
            HaClientError::ProtocolViolation,
            static fn() => ExposedEntitySnapshot::fromUpsertResult(['entity_id' => 'tank', 'state' => 1, 'attributes' => [], 'available' => true]),
        );
    }
}
