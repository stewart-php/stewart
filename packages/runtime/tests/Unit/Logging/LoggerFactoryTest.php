<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Logging\ExceptionContextProcessor;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Runtime\Logging\LogOrigin;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Logging\ProcessTimeZoneProcessor;
use Stewart\Runtime\Logging\StackTraceLineFormatter;
use Stewart\Runtime\Logging\TraceLabelJsonFormatter;
use Stewart\Runtime\Logging\VerbosityFilterHandler;
use Stewart\Runtime\Model\AppVerbosity;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(LoggerFactory::class)]
#[CoversClass(StackTraceLineFormatter::class)]
#[CoversClass(ExceptionContextProcessor::class)]
#[CoversClass(ProcessTimeZoneProcessor::class)]
#[CoversClass(VerbosityFilterHandler::class)]
#[CoversClass(LogOrigin::class)]
#[CoversClass(TraceLabelJsonFormatter::class)]
final class LoggerFactoryTest extends TestCase
{
    private TempDirectory $temp;

    private string $originalZone;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-log-');
        $this->originalZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
        $this->temp->remove();
    }

    public function testLineCarriesDateAndZoneAdoptedLater(): void
    {
        date_default_timezone_set('UTC');
        $logger = $this->createFactory()->createStdoutLogger();
        date_default_timezone_set('Asia/Tokyo');

        $logger->info('Connected');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\+09:00 INFO Connected *\n$/', $this->readLog());
    }

    public function testUnexpectedExceptionPrintsTraceBelow(): void
    {
        $error = new RuntimeException('boom');

        $this->createFactory()->createStdoutLogger()->error('Stewart stopped', ['exception' => $error]);

        $lines = explode("\n", $this->readLog());
        self::assertStringContainsString('ERROR Stewart stopped', $lines[0]);
        self::assertStringNotContainsString('#0 ', $lines[0]);
        self::assertSame(explode("\n", $error->getTraceAsString())[0], $lines[1]);
        self::assertStringEndsWith("{main}\n", $this->readLog());
    }

    public function testStewartExceptionPrintsNoTrace(): void
    {
        $this->createFactory()->createStdoutLogger()->error('Store failed', ['exception' => StoreException::createForReason(StoreError::NotConfigured)]);

        self::assertSame(1, substr_count($this->readLog(), "\n"));
        self::assertStringNotContainsString('#0 ', $this->readLog());
    }

    public function testUnwritableStreamFallsBack(): void
    {
        $fallback = $this->temp->getFilePath('fallback.log');

        new LoggerFactory($this->temp->path, $fallback)->createStdoutLogger()->warning('Shutting down');

        self::assertStringContainsString('WARNING Shutting down', (string) file_get_contents($fallback));
    }

    public function testJsonCarriesTraceAsField(): void
    {
        $error = new RuntimeException('boom');

        $this->createFactory()->createStdoutLogger(LogLevel::Info, LogFormat::Json)->error('Stewart stopped', ['exception' => $error]);

        $record = json_decode($this->readLog(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $extra = $record['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame($error->getTraceAsString(), $extra[ExceptionContextProcessor::TRACE_KEY] ?? null);
    }

    public function testDaemonLinesNameTheirSourceAfterTheLevel(): void
    {
        $logger = $this->createFactory()->createDaemonLogger(new LogVerbosityPolicy(LogLevel::Info));

        $logger->info('Connected');
        $logger->info('Turning on', ['entity' => 'light.porch', 'app' => 'porch', 'worker' => 0, LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('porch'))]);
        $logger->info('Registry loaded', ['app' => '@shared', LogOrigin::CONTEXT_KEY => LogOrigin::forScope(ResourceScope::shared())]);
        $logger->notice('Fatal error: oops', ['worker' => 1, 'stream' => 'stderr', LogOrigin::CONTEXT_KEY => LogOrigin::forWorkerOutput(new WorkerId(1))]);

        $lines = explode("\n", trim($this->readLog()));
        self::assertMatchesRegularExpression('/ INFO \[broker\] Connected *$/', $lines[0]);
        self::assertMatchesRegularExpression('/ INFO \[porch\] Turning on \{"entity":"light.porch","worker":0\} *$/', $lines[1]);
        self::assertMatchesRegularExpression('/ INFO \[@shared\] Registry loaded *$/', $lines[2]);
        self::assertMatchesRegularExpression('/ NOTICE \[worker 1\] Fatal error: oops \{"worker":1,"stream":"stderr"\} *$/', $lines[3]);
    }

    public function testDaemonLineKeepsTheAppOfARecordAboutAnotherApp(): void
    {
        $this->createFactory()->createDaemonLogger(new LogVerbosityPolicy(LogLevel::Info))->info('App paused', ['app' => 'porch', 'source' => 'control']);

        self::assertStringContainsString('INFO [broker] App paused {"app":"porch","source":"control"}', $this->readLog());
    }

    public function testDaemonFiltersEachRecordByTheLevelOfItsApp(): void
    {
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, [
            'porch' => new AppVerbosity(LogLevel::Debug),
            'chatty' => new AppVerbosity(LogLevel::Warning),
        ]);
        $logger = $this->createFactory()->createDaemonLogger($verbosity);

        $logger->debug('broker detail');
        $logger->debug('porch detail', [LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('porch'))]);
        $logger->info('chatty news', [LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('chatty'))]);
        $logger->debug('hall detail', [LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('hall'))]);

        $log = $this->readLog();
        self::assertSame(1, substr_count($log, "\n"));
        self::assertStringContainsString('DEBUG [porch] porch detail', $log);
    }

    public function testDaemonJsonKeepsTheAppInContextAndAddsTheSource(): void
    {
        $this->createFactory()->createDaemonLogger(new LogVerbosityPolicy(LogLevel::Info), LogFormat::Json)
            ->info('Turning on', ['app' => 'porch', 'worker' => 0, LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('porch'))]);

        $record = json_decode($this->readLog(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame(['app' => 'porch', 'worker' => 0], $record['context'] ?? null);
        self::assertSame(['source' => 'porch'], $record['extra'] ?? null);
    }

    public function testTraceRecordPrintsTraceAndPassesAnyLevel(): void
    {
        $logger = $this->createFactory()->createDaemonLogger(new LogVerbosityPolicy(LogLevel::Error));

        $logger->debug('Handler called', ['id' => 'w0:1', LogOrigin::CONTEXT_KEY => LogOrigin::forScope(ResourceScope::forApp(new AppId('porch')), traced: true)]);
        $logger->debug('porch detail', [LogOrigin::CONTEXT_KEY => LogOrigin::forApp(new AppId('porch'))]);

        $log = $this->readLog();
        self::assertSame(1, substr_count($log, "\n"));
        self::assertMatchesRegularExpression('/ TRACE \[porch\] Handler called \{"id":"w0:1"\} *$/', trim($log));
    }

    public function testJsonTraceRecordKeepsTheDebugLevelUnderItsOwnName(): void
    {
        $this->createFactory()->createDaemonLogger(new LogVerbosityPolicy(LogLevel::Error), LogFormat::Json)
            ->debug('Handler called', [LogOrigin::CONTEXT_KEY => LogOrigin::forScope(ResourceScope::forApp(new AppId('porch')), traced: true)]);

        $record = json_decode($this->readLog(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('TRACE', $record['level_name'] ?? null);
        self::assertSame(100, $record['level'] ?? null);
        self::assertSame(['source' => 'porch'], $record['extra'] ?? null);
    }

    private function createFactory(): LoggerFactory
    {
        return new LoggerFactory($this->temp->getFilePath('stewart.log'), $this->temp->getFilePath('fallback.log'));
    }

    private function readLog(): string
    {
        return (string) file_get_contents($this->temp->getFilePath('stewart.log'));
    }
}
