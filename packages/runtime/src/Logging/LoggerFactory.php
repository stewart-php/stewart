<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Model\LogLevel;

final readonly class LoggerFactory
{
    private const string LINE_FORMAT = "%datetime% %level_name% %message% %context% %extra%\n";

    private const string LINE_DATE_FORMAT = 'Y-m-d H:i:s.vP';

    public function __construct(
        private string $logStreamUrl = 'php://stdout',
        private string $logFallbackStreamUrl = 'php://stderr',
    ) {}

    public function createStdoutLogger(LogLevel $level = LogLevel::Info, LogFormat $format = LogFormat::Line): LoggerInterface
    {
        $threshold = Level::fromName($level->name);
        // A failed write must not throw out of a log call, e.g. while the broker is stopping.
        $handler = new FallbackGroupHandler([
            new StreamHandler($this->logStreamUrl, $threshold),
            new StreamHandler($this->logFallbackStreamUrl, $threshold),
        ]);
        $handler->setFormatter($this->createFormatter($format));

        return new Logger('stewart')
            ->pushHandler($handler)
            ->pushProcessor(new ExceptionContextProcessor())
            ->pushProcessor(new ProcessTimeZoneProcessor());
    }

    private function createFormatter(LogFormat $format): FormatterInterface
    {
        return match ($format) {
            LogFormat::Json => new JsonFormatter(),
            LogFormat::Line => new StackTraceLineFormatter(
                format: self::LINE_FORMAT,
                dateFormat: self::LINE_DATE_FORMAT,
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            ),
        };
    }
}
