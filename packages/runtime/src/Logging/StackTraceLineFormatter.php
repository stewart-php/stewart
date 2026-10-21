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
        $line = parent::format($this->dropRepeatedDetails($record));

        return \is_string($trace) ? $line . $trace . "\n" : $line;
    }

    private function dropRepeatedDetails(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $extra = $record->extra;
        unset($extra[ExceptionContextProcessor::TRACE_KEY]);

        // The source tag already names the app; a line about another app keeps its key.
        if (isset($context['app'], $extra[LogOrigin::SOURCE_KEY]) && $context['app'] === $extra[LogOrigin::SOURCE_KEY]) {
            unset($context['app']);
        }

        return $record->with(context: $context, extra: $extra);
    }
}
