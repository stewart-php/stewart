<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Tests\Fixtures\Exception\SampleError;
use Stewart\Contracts\Tests\Fixtures\Exception\SampleException;

#[CoversClass(StewartException::class)]
final class StewartExceptionTest extends TestCase
{
    public function testTemplateWithoutPlaceholdersLeavesContextNull(): void
    {
        $exception = SampleException::createForReason(SampleError::Plain);

        self::assertSame('Nothing to fill.', $exception->getMessage());
        self::assertSame(SampleError::Plain, $exception->reason);
        self::assertNull($exception->context);
    }

    public function testPlaceholdersRenderContextValues(): void
    {
        $exception = SampleException::createForReason(SampleError::Placeholders, key: 'k', count: 3, flag: false, items: ['a', 'b'], absent: null);

        self::assertSame('Key "k", count 3, flag false, list a, b, missing none.', $exception->getMessage());
        self::assertSame(['key' => 'k', 'count' => 3, 'flag' => false, 'items' => ['a', 'b'], 'absent' => null], $exception->context);
    }

    public function testCauseComesFromPreviousAndStaysOutOfContext(): void
    {
        $previous = new RuntimeException('disk full');

        $exception = SampleException::createForReason(SampleError::Caused, $previous);

        self::assertSame('Failed: disk full', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull($exception->context);
    }

    public function testUnknownPlaceholderIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('{key}');

        // @phpstan-ignore stewart.exceptionContextMismatch
        SampleException::createForReason(SampleError::Placeholders);
    }

    public function testCauseWithoutPreviousIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        // @phpstan-ignore stewart.exceptionContextMismatch
        SampleException::createForReason(SampleError::Caused);
    }

    public function testPositionalContextIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        // @phpstan-ignore stewart.exceptionContextMismatch
        SampleException::createForReason(SampleError::Plain, null, 'unnamed');
    }
}
