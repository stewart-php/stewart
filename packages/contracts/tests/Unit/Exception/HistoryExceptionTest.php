<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;

#[CoversClass(HistoryException::class)]
#[CoversClass(HistoryError::class)]
final class HistoryExceptionTest extends TestCase
{
    public function testRejectionAppendsHaCodeWhenGiven(): void
    {
        $entityId = EntityId::fromStringOrId('light.hall');

        self::assertSame(
            'History of light.hall was rejected by Home Assistant: Invalid time (invalid_format)',
            HistoryException::rejected($entityId, 'Invalid time', 'invalid_format')->getMessage(),
        );
        self::assertSame(
            'History of light.hall was rejected by Home Assistant: Invalid time',
            HistoryException::rejected($entityId, 'Invalid time')->getMessage(),
        );
    }

    public function testWireRoundTripKeepsReasonMessageAndContext(): void
    {
        $original = HistoryException::timedOut(EntityId::fromStringOrId('light.hall'), 'no answer');

        $rebuilt = HistoryException::fromWire($original->reason, $original->getMessage(), $original->context);

        self::assertSame(HistoryError::TimedOut, $rebuilt->reason);
        self::assertSame($original->getMessage(), $rebuilt->getMessage());
        self::assertSame($original->context, $rebuilt->context);
    }
}
