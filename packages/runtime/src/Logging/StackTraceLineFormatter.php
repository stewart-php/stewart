<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

final class StackTraceLineFormatter extends LineFormatter
{
    public function format(LogRecord $record): string
    {
        $trace = $record->extra[ExceptionContextProcessor::TRACE_KEY] ?? null;

        if (!\is_string($trace)) {
            return parent::format($record);
        }

        $extra = $record->extra;
        unset($extra[ExceptionContextProcessor::TRACE_KEY]);

        return parent::format($record->with(extra: $extra)) . $trace . "\n";
    }
}
