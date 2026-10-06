<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Exposition;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Metrics\Exposition\Histogram;
use Stewart\Runtime\Metrics\Exposition\HistogramBucket;

#[CoversClass(Histogram::class)]
final class HistogramTest extends TestCase
{
    public function testLatencyBoundsBecomeSecondsWithInfBucket(): void
    {
        $histogram = Histogram::fromLatencyHistogram(new LatencyHistogram([5, 250], [2, 9], 10, Duration::milliseconds(80)));

        self::assertEquals(
            [new HistogramBucket(0.005, 2), new HistogramBucket(0.25, 9), new HistogramBucket(INF, 10)],
            $histogram->buckets->listValues(),
        );
        self::assertSame(10, $histogram->count);
        self::assertSame(0.08, $histogram->sum->toSeconds());
    }

    public function testMismatchedBoundsAndCountsAreRefused(): void
    {
        $this->expectException(LogicException::class);

        Histogram::fromLatencyHistogram(new LatencyHistogram([5, 250], [2], 10, Duration::milliseconds(80)));
    }
}
