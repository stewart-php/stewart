<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\CgroupCpuQuota;

#[CoversClass(CgroupCpuQuota::class)]
final class CgroupCpuQuotaTest extends TestCase
{
    #[DataProvider('provideLimits')]
    public function testQuotaRoundsUpToWholeCpus(string $cpuMax, int $expected): void
    {
        self::assertSame($expected, CgroupCpuQuota::parseCpuMax($cpuMax)?->cpus);
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideLimits(): iterable
    {
        yield 'one cpu' => ["100000 100000\n", 1];
        yield 'a fraction rounds up' => ['150000 100000', 2];
        yield 'less than one cpu is still one' => ['20000 100000', 1];
        yield 'a short period' => ['300000 50000', 6];
    }

    #[DataProvider('provideNoLimits')]
    public function testNoUsableLimitIsNull(string $cpuMax): void
    {
        self::assertNull(CgroupCpuQuota::parseCpuMax($cpuMax));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNoLimits(): iterable
    {
        yield 'unlimited' => ["max 100000\n"];
        yield 'empty' => [''];
        yield 'one field' => ['100000'];
        yield 'zero period' => ['100000 0'];
        yield 'not numbers' => ['lots often'];
    }
}
