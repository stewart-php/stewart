<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Exposition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Metrics\Exposition\HistogramBuckets;

#[CoversClass(HistogramBuckets::class)]
final class HistogramBucketsTest extends TestCase
{
    public function testLatencyBoundsBecomeSecondsWithInfBucket(): void
    {
        $buckets = HistogramBuckets::fromLatencyHistogram(new LatencyHistogram([5, 250], [2, 9], 10, Duration::milliseconds(80)));

        self::assertSame([0.005, 0.25, INF], $buckets->upperBounds);
        self::assertSame([2, 9, 10], $buckets->cumulativeCounts);
        self::assertSame(10, $buckets->count);
        self::assertSame(0.08, $buckets->sum->toSeconds());
    }
}
