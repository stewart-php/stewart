<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;

#[CoversClass(ServiceCallException::class)]
#[CoversClass(ServiceCallError::class)]
final class ServiceCallExceptionTest extends TestCase
{
    public function testMessageNamesServiceReasonAndHaCode(): void
    {
        $failure = ServiceCallException::rejected('light', 'turn_on', 'Entity not found', 'not_found');

        self::assertSame('light.turn_on was rejected by Home Assistant: Entity not found (not_found)', $failure->getMessage());
        self::assertSame(ServiceCallError::Rejected, $failure->reason);
        self::assertSame('not_found', $failure->context['errorCode'] ?? null);
    }

    public function testEachReasonReadsDifferently(): void
    {
        self::assertStringContainsString('could not reach Home Assistant', ServiceCallException::unreachable('a', 'b', 'x')->getMessage());
        self::assertStringContainsString('timed out', ServiceCallException::timedOut('a', 'b', 'x')->getMessage());
    }

    public function testWireRoundTripKeepsReasonMessageAndContext(): void
    {
        $original = ServiceCallException::overloaded('light', 'turn_on', 'busy');

        $rebuilt = ServiceCallException::fromWire($original->reason, $original->getMessage(), $original->context);

        self::assertSame(ServiceCallError::Overloaded, $rebuilt->reason);
        self::assertSame($original->getMessage(), $rebuilt->getMessage());
        self::assertSame($original->context, $rebuilt->context);
    }
}
