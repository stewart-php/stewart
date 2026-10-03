<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Logging\ProcessTimeZoneProcessor;

#[CoversClass(ProcessTimeZoneProcessor::class)]
final class ProcessTimeZoneProcessorTest extends TestCase
{
    private string $originalZone;

    protected function setUp(): void
    {
        $this->originalZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
    }

    public function testRecordMovesToProcessZoneKeepingInstant(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $original = self::createLogRecordAt('2026-10-02T05:00:00+00:00');

        $record = new ProcessTimeZoneProcessor()($original);

        self::assertSame('2026-10-02T14:00:00+09:00', $record->datetime->format(\DATE_ATOM));
        self::assertSame($original->datetime->getTimestamp(), $record->datetime->getTimestamp());
    }

    public function testLaterZoneChangeIsFollowed(): void
    {
        $processor = new ProcessTimeZoneProcessor();
        date_default_timezone_set('Asia/Tokyo');
        $processor(self::createLogRecordAt('2026-10-02T05:00:00+00:00'));

        date_default_timezone_set('America/Sao_Paulo');
        $record = $processor(self::createLogRecordAt('2026-10-02T05:00:00+00:00'));

        self::assertSame('2026-10-02T02:00:00-03:00', $record->datetime->format(\DATE_ATOM));
    }

    private static function createLogRecordAt(string $moment): LogRecord
    {
        return new LogRecord(new DateTimeImmutable($moment)->setTimezone(new DateTimeZone('UTC')), 'stewart', Level::Info, 'logged');
    }
}
