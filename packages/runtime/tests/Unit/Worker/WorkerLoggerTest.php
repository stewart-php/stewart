<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use stdClass;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerLogger;

#[CoversClass(WorkerLogger::class)]
final class WorkerLoggerTest extends TestCase
{
    public function testUnknownLevelIsRejectedWhereTheAppLogged(): void
    {
        $logger = new WorkerLogger(new NullTransport(), new StderrFallback(new WorkerId(0)), ResourceScope::forApp(new AppId('demo')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level "trace"');

        $logger->log('trace', 'nope');
    }

    public function testRecordsBelowTheThresholdStayInTheWorker(): void
    {
        $transport = new NullTransport();
        $logger = new WorkerLogger($transport, new StderrFallback(new WorkerId(0)), ResourceScope::forApp(new AppId('demo')), LogLevel::Warning);

        $logger->info('quiet');
        $logger->error('loud');

        self::assertCount(1, $transport->sent);
        self::assertInstanceOf(LogRecord::class, $transport->sent[0]);
        self::assertSame(LogLevel::Error, $transport->sent[0]->level);
    }

    public function testContextIsReducedToValuesThatCrossProcesses(): void
    {
        $transport = new NullTransport();
        $logger = new WorkerLogger($transport, new StderrFallback(new WorkerId(0)), ResourceScope::forApp(new AppId('worker')));

        $logger->forApp(new AppId('demo'))->warning('context', [
            'list' => [1, 'two', new stdClass()],
            'error' => new RuntimeException('broken'),
        ]);

        $record = $transport->sent[0];

        self::assertInstanceOf(LogRecord::class, $record);
        self::assertSame('demo', (string) $record->scope);
        self::assertSame([1, 'two', 'stdClass'], $record->context['list']);
        self::assertIsString($record->context['error']);
        self::assertStringStartsWith('RuntimeException: broken in ', $record->context['error']);
    }
}
