<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Logging\ExceptionContextProcessor;
use Stewart\Runtime\Model\ExceptionDetails;

#[CoversClass(ExceptionContextProcessor::class)]
#[CoversClass(ExceptionDetails::class)]
final class ExceptionContextProcessorTest extends TestCase
{
    public function testLoggedStewartExceptionBringsItsCodeAndContext(): void
    {
        $error = StoreException::keyInvalid('with space');

        $record = new ExceptionContextProcessor()(self::createLogRecord(['exception' => $error]));

        self::assertSame(
            ['class' => StoreException::class, 'reason' => 'key_invalid', 'context' => ['key' => 'with space']],
            $record->extra['error'] ?? null,
        );
        self::assertSame($error, $record->context['exception']);
    }

    public function testWorkerDetailsMoveFromContextToExtra(): void
    {
        $details = ExceptionDetails::fromThrowable(StoreException::notConfigured());

        $record = new ExceptionContextProcessor()(self::createLogRecord(['app' => 'demo', ExceptionDetails::CONTEXT_KEY => $details]));

        self::assertSame(['app' => 'demo'], $record->context);
        self::assertSame($details?->toArray(), $record->extra['error'] ?? null);
    }

    public function testEmptyForwardingSlotLeavesNoTrace(): void
    {
        $record = new ExceptionContextProcessor()(self::createLogRecord(['app' => 'demo', ExceptionDetails::CONTEXT_KEY => null]));

        self::assertSame(['app' => 'demo'], $record->context);
        self::assertArrayNotHasKey('error', $record->extra);
    }

    public function testOtherExceptionBringsItsTrace(): void
    {
        $error = new RuntimeException('boom');

        $record = new ExceptionContextProcessor()(self::createLogRecord(['exception' => $error]));

        self::assertSame($error->getTraceAsString(), $record->extra[ExceptionContextProcessor::TRACE_KEY] ?? null);
        self::assertArrayNotHasKey('error', $record->extra);
        self::assertSame($error, $record->context['exception']);
    }

    public function testStewartExceptionBringsNoTrace(): void
    {
        $record = new ExceptionContextProcessor()(self::createLogRecord(['exception' => StoreException::notConfigured()]));

        self::assertArrayNotHasKey(ExceptionContextProcessor::TRACE_KEY, $record->extra);
    }

    public function testRecordWithoutExceptionIsLeftAlone(): void
    {
        $original = self::createLogRecord(['exception' => 'already a string', 'app' => 'demo']);

        self::assertSame($original, new ExceptionContextProcessor()($original));
    }

    /** @param array<string, mixed> $context */
    private static function createLogRecord(array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'stewart', Level::Error, 'failed', $context);
    }
}
