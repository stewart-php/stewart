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
        $exception = SampleException::createForSampleReason(SampleError::Plain);

        self::assertSame('Nothing to fill.', $exception->getMessage());
        self::assertSame(SampleError::Plain, $exception->reason);
        self::assertNull($exception->context);
    }

    public function testPlaceholdersRenderContextValues(): void
    {
        $context = ['key' => 'k', 'count' => 3, 'flag' => false, 'items' => ['a', 'b'], 'absent' => null];

        $exception = SampleException::createForSampleReason(SampleError::Placeholders, $context);

        self::assertSame('Key "k", count 3, flag false, list a, b, missing none.', $exception->getMessage());
        self::assertSame($context, $exception->context);
    }

    public function testCauseComesFromPreviousAndStaysOutOfContext(): void
    {
        $previous = new RuntimeException('disk full');

        $exception = SampleException::createForSampleReason(SampleError::Caused, [], $previous);

        self::assertSame('Failed: disk full', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull($exception->context);
    }

    public function testUnknownPlaceholderIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('{key}');

        SampleException::createForSampleReason(SampleError::Placeholders);
    }

    public function testCauseWithoutPreviousIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        SampleException::createForSampleReason(SampleError::Caused);
    }
}
