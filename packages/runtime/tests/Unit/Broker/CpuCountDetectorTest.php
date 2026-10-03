<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\CpuCountDetector;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(CpuCountDetector::class)]
final class CpuCountDetectorTest extends TestCase
{
    private TempDirectory $cgroup;

    protected function setUp(): void
    {
        $this->cgroup = TempDirectory::createWithPrefix('stewart-cgroup-');
    }

    protected function tearDown(): void
    {
        $this->cgroup->remove();
    }

    public function testCgroupQuotaCapsCpuCount(): void
    {
        self::assertSame(1, new CpuCountDetector($this->writeCpuMax("100000 100000\n"))->detectCpuCount());
    }

    public function testUnlimitedCgroupKeepsHostCount(): void
    {
        $hostCount = new CpuCountDetector($this->cgroup->getFilePath('absent'))->detectCpuCount();

        self::assertSame($hostCount, new CpuCountDetector($this->writeCpuMax("max 100000\n"))->detectCpuCount());
        self::assertGreaterThanOrEqual(1, $hostCount);
    }

    private function writeCpuMax(string $contents): string
    {
        $path = $this->cgroup->getFilePath('cpu.max');
        file_put_contents($path, $contents);

        return $path;
    }
}
