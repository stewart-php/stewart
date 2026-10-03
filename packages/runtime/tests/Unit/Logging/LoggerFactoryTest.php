<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Logging\ExceptionContextProcessor;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Runtime\Logging\ProcessTimeZoneProcessor;
use Stewart\Runtime\Logging\StackTraceLineFormatter;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(LoggerFactory::class)]
#[CoversClass(StackTraceLineFormatter::class)]
#[CoversClass(ExceptionContextProcessor::class)]
#[CoversClass(ProcessTimeZoneProcessor::class)]
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
        $this->createFactory()->createStdoutLogger()->error('Store failed', ['exception' => StoreException::notConfigured()]);

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

    private function createFactory(): LoggerFactory
    {
        return new LoggerFactory($this->temp->getFilePath('stewart.log'), $this->temp->getFilePath('fallback.log'));
    }

    private function readLog(): string
    {
        return (string) file_get_contents($this->temp->getFilePath('stewart.log'));
    }
}
