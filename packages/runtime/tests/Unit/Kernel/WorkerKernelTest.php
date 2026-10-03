<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Time\SystemClock;

#[CoversClass(WorkerKernel::class)]
final class WorkerKernelTest extends TestCase
{
    private RecordingProcessTimeZone $processTimeZone;

    protected function setUp(): void
    {
        $this->processTimeZone = new RecordingProcessTimeZone();
    }

    public function testWorkerInItsOwnProcessAdoptsHomeAssistantsZone(): void
    {
        $summary = $this->runUntilHangUp();

        self::assertStringContainsString('worker 0 stopped (channel closed', $summary);
        self::assertSame('America/New_York', $this->processTimeZone->adopted?->getName());
    }

    public function testAnythingButBootstrapFirstStopsTheWorker(): void
    {
        $transport = new FakeWorkerTransport();
        $transport->deliver(new Ping(1, SystemClock::inUtc()->getNow()));

        $summary = $this->createKernel()->run($transport);

        self::assertStringContainsString('expected Bootstrap first', $summary);
    }

    private function runUntilHangUp(): string
    {
        $transport = new FakeWorkerTransport();
        $transport->deliver(TestBootstrap::createForApps([], timeZone: 'America/New_York'));
        $transport->hangUp();

        return $this->createKernel()->run($transport);
    }

    private function createKernel(): WorkerKernel
    {
        return new WorkerKernel(new SyntheticServices()->withService(ProcessTimeZone::class, $this->processTimeZone));
    }
}
