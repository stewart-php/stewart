<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Model\LogLevel;

final readonly class LoggerFactory
{
    private const string LINE_FORMAT = "%datetime% %level_name% %message% %context% %extra%\n";

    private const string DAEMON_LINE_FORMAT = "%datetime% %level_name% [%extra.source%] %message% %context% %extra%\n";

    private const string LINE_DATE_FORMAT = 'Y-m-d H:i:s.vP';

    public function __construct(
        private string $logStreamUrl = 'php://stdout',
        private string $logFallbackStreamUrl = 'php://stderr',
    ) {}

    public function createStdoutLogger(LogLevel $level = LogLevel::Info, LogFormat $format = LogFormat::Line): LoggerInterface
    {
        $handler = $this->createStreamHandler(Level::fromName($level->name));
        $handler->setFormatter($this->createFormatter($format, self::LINE_FORMAT));

        return $this->createLoggerFor($handler);
    }

    // Every record reaches the filter, which applies the level of the app the record is about.
    public function createDaemonLogger(LogVerbosityPolicy $verbosity, LogFormat $format = LogFormat::Line): LoggerInterface
    {
        $handler = $this->createStreamHandler(Level::Debug);
        $handler->setFormatter($this->createFormatter($format, self::DAEMON_LINE_FORMAT));

        return $this->createLoggerFor(new VerbosityFilterHandler($handler, $verbosity));
    }

    private function createLoggerFor(HandlerInterface $handler): LoggerInterface
    {
        return new Logger('stewart')
            ->pushHandler($handler)
            ->pushProcessor(new ExceptionContextProcessor())
            ->pushProcessor(new ProcessTimeZoneProcessor());
    }

    private function createStreamHandler(Level $threshold): FallbackGroupHandler
    {
        // A failed write must not throw out of a log call, e.g. while the broker is stopping.
        return new FallbackGroupHandler([
            new StreamHandler($this->logStreamUrl, $threshold),
            new StreamHandler($this->logFallbackStreamUrl, $threshold),
        ]);
    }

    private function createFormatter(LogFormat $format, string $lineFormat): FormatterInterface
    {
        return match ($format) {
            LogFormat::Json => new TraceLabelJsonFormatter(),
            LogFormat::Line => new StackTraceLineFormatter(
                format: $lineFormat,
                dateFormat: self::LINE_DATE_FORMAT,
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            ),
        };
    }
}
