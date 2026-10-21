<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

final class TraceLabelJsonFormatter extends JsonFormatter
{
    protected function normalizeRecord(LogRecord $record): array
    {
        return LogOrigin::relabelTracedRecord(parent::normalizeRecord($record));
    }
}
