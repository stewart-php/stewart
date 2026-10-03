<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stewart\Runtime\Model\ExceptionDetails;
use Throwable;

final class ExceptionContextProcessor implements ProcessorInterface
{
    public const string TRACE_KEY = 'trace';

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $forwarded = $context[ExceptionDetails::CONTEXT_KEY] ?? null;
        $wasForwarded = \array_key_exists(ExceptionDetails::CONTEXT_KEY, $context);
        unset($context[ExceptionDetails::CONTEXT_KEY]);

        $exception = $context['exception'] ?? null;
        $details = $forwarded instanceof ExceptionDetails
            ? $forwarded
            : ($exception instanceof Throwable ? ExceptionDetails::fromThrowable($exception) : null);

        if ($details !== null) {
            return $record->with(context: $context, extra: [...$record->extra, 'error' => $details->toArray()]);
        }

        // A Stewart exception is an expected failure; anything else is a bug that needs its trace.
        if ($exception instanceof Throwable) {
            return $record->with(context: $context, extra: [...$record->extra, self::TRACE_KEY => $exception->getTraceAsString()]);
        }

        return $wasForwarded ? $record->with(context: $context) : $record;
    }
}
