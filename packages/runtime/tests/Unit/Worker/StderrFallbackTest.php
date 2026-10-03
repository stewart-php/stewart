<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerLogger;

#[CoversClass(StderrFallback::class)]
#[CoversClass(WorkerLogger::class)]
final class StderrFallbackTest extends TestCase
{
    private string $stream;

    protected function setUp(): void
    {
        $this->stream = tempnam(sys_get_temp_dir(), 'stewart-stderr') ?: self::fail('No temporary file.');
    }

    protected function tearDown(): void
    {
        @unlink($this->stream);
    }

    public function testLineCarriesWorkerAndScope(): void
    {
        new StderrFallback(new WorkerId(3), $this->stream)->writeLine(ResourceScope::forApp(new AppId('demo')), 'hello');

        self::assertSame("[worker 3][demo] hello\n", file_get_contents($this->stream));
    }

    public function testLoggerFallsBackWhenTransportFails(): void
    {
        $logger = new WorkerLogger(new FailingTransport(new RuntimeException('closed')), new StderrFallback(new WorkerId(0), $this->stream), ResourceScope::shared());

        $logger->warning('channel gone');

        self::assertStringEndsWith("warning channel gone\n", (string) file_get_contents($this->stream));
    }
}
