<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ReconcileResultReader;
use Stewart\Client\Exception\HaClientError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ReconcileResultReader::class)]
final class ReconcileResultReaderTest extends TestCase
{
    use AssertsReason;

    public function testEmptyRemovalIsRead(): void
    {
        self::assertTrue(ReconcileResultReader::readRemovedEntityIds(['removed' => []])->isEmpty());
    }

    public function testResultWithoutRemovedIsProtocolViolation(): void
    {
        $this->assertThrowsReason(
            HaClientError::ProtocolViolation,
            static fn() => ReconcileResultReader::readRemovedEntityIds([]),
        );
    }

    public function testMalformedEntityIdIsProtocolViolation(): void
    {
        $this->assertThrowsReason(
            HaClientError::ProtocolViolation,
            static fn() => ReconcileResultReader::readRemovedEntityIds(['removed' => ['tank']]),
        );
    }

    public function testNonStringEntityIdIsProtocolViolation(): void
    {
        $this->assertThrowsReason(
            HaClientError::ProtocolViolation,
            static fn() => ReconcileResultReader::readRemovedEntityIds(['removed' => [42]]),
        );
    }
}
