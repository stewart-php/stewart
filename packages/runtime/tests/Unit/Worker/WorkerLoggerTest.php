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
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\AppVerbosity;
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
        $logger = $this->createLogger(new NullTransport(), new LogVerbosityPolicy(LogLevel::Debug), 'demo');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown log level "trace"');

        $logger->log('trace', 'nope');
    }

    public function testRecordsBelowTheThresholdStayInTheWorker(): void
    {
        $transport = new NullTransport();
        $logger = $this->createLogger($transport, new LogVerbosityPolicy(LogLevel::Warning), 'demo');

        $logger->info('quiet');
        $logger->error('loud');

        self::assertSame([LogLevel::Error], $this->listSentLevels($transport));
    }

    public function testAppLevelRaisesOneAppAboveTheGlobalLevel(): void
    {
        $transport = new NullTransport();
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, ['porch' => new AppVerbosity(LogLevel::Debug)]);
        $logger = $this->createLogger($transport, $verbosity, 'porch');

        $logger->debug('porch detail');
        $logger->forApp(new AppId('hall'))->debug('hall detail');
        $logger->forScope(ResourceScope::shared())->debug('shared detail');

        self::assertSame([LogLevel::Debug], $this->listSentLevels($transport));
    }

    public function testAppLevelQuietsOneAppBelowTheGlobalLevel(): void
    {
        $transport = new NullTransport();
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, ['chatty' => new AppVerbosity(LogLevel::Warning)]);
        $logger = $this->createLogger($transport, $verbosity, 'chatty');

        $logger->info('chatter');
        $logger->warning('worth saying');
        $logger->forApp(new AppId('hall'))->info('hall news');

        self::assertSame([LogLevel::Warning, LogLevel::Info], $this->listSentLevels($transport));
    }

    public function testContextIsReducedToValuesThatCrossProcesses(): void
    {
        $transport = new NullTransport();
        $logger = $this->createLogger($transport, new LogVerbosityPolicy(LogLevel::Debug), 'worker');

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

    private function createLogger(NullTransport $transport, LogVerbosityPolicy $verbosity, string $appId): WorkerLogger
    {
        return new WorkerLogger($transport, new StderrFallback(new WorkerId(0)), ResourceScope::forApp(new AppId($appId)), $verbosity);
    }

    /** @return list<LogLevel> */
    private function listSentLevels(NullTransport $transport): array
    {
        $levels = [];

        foreach ($transport->sent as $message) {
            self::assertInstanceOf(LogRecord::class, $message);
            $levels[] = $message->level;
        }

        return $levels;
    }
}
