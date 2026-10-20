<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Health\ProcessMemory;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(ProcessMemory::class)]
final class ProcessMemoryTest extends TestCase
{
    private TempDirectory $proc;

    protected function setUp(): void
    {
        $this->proc = TempDirectory::createWithPrefix('stewart-statm-');
    }

    protected function tearDown(): void
    {
        $this->proc->remove();
    }

    public function testResidentPagesAreScaledByPageSize(): void
    {
        $memory = new ProcessMemory($this->writeStatm("12000 2500 900 200 0 4100 0\n"));

        self::assertSame(2500 * posix_sysconf(\POSIX_SC_PAGESIZE), $memory->measureResidentBytes());
    }

    public function testMissingStatmFallsBackToHeap(): void
    {
        self::assertGreaterThan(0, new ProcessMemory($this->proc->getFilePath('absent'))->measureResidentBytes());
    }

    public function testMalformedStatmFallsBackToHeap(): void
    {
        self::assertGreaterThan(0, new ProcessMemory($this->writeStatm("garbage\n"))->measureResidentBytes());
    }

    private function writeStatm(string $contents): string
    {
        $path = $this->proc->getFilePath('statm');
        file_put_contents($path, $contents);

        return $path;
    }
}
