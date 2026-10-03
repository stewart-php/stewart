<?php

declare(strict_types=1);

namespace Stewart\Testing\Logging\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\Logging\RecordedLog;

/** @extends ListCollection<RecordedLog> */
final readonly class RecordedLogCollection extends ListCollection
{
    /** @param iterable<RecordedLog> $logs */
    public static function fromLogs(iterable $logs): self
    {
        return self::fromList($logs);
    }

    public function withRecordedLog(RecordedLog $log): self
    {
        return $this->withAppendedElement($log);
    }
}
