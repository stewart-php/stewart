<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Contracts\Exception\ActionCallException;

#[CoversClass(ActionCallException::class)]
#[CoversClass(ActionCallError::class)]
final class ActionCallExceptionTest extends TestCase
{
    public function testMessageNamesServiceReasonAndHaCode(): void
    {
        $failure = ActionCallException::rejected('light', 'turn_on', 'Entity not found', 'not_found');

        self::assertSame('light.turn_on was rejected by Home Assistant: Entity not found (not_found)', $failure->getMessage());
        self::assertSame(ActionCallError::Rejected, $failure->reason);
        self::assertSame('not_found', $failure->context['errorCode'] ?? null);
    }

    public function testEachReasonReadsDifferently(): void
    {
        self::assertStringContainsString('could not reach Home Assistant', ActionCallException::unreachable('a', 'b', 'x')->getMessage());
        self::assertStringContainsString('timed out', ActionCallException::timedOut('a', 'b', 'x')->getMessage());
    }

    public function testWireRoundTripKeepsReasonMessageAndContext(): void
    {
        $original = ActionCallException::overloaded('light', 'turn_on', 'busy');

        $rebuilt = ActionCallException::restoreFromWire($original->reason, $original->getMessage(), $original->context);

        self::assertSame(ActionCallError::Overloaded, $rebuilt->reason);
        self::assertSame($original->getMessage(), $rebuilt->getMessage());
        self::assertSame($original->context, $rebuilt->context);
    }
}
