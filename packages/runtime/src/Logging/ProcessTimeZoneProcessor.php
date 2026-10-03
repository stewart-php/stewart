<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use DateTimeZone;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class ProcessTimeZoneProcessor implements ProcessorInterface
{
    private ?DateTimeZone $zone = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(datetime: $record->datetime->setTimezone($this->findProcessZone()));
    }

    private function findProcessZone(): DateTimeZone
    {
        $name = date_default_timezone_get();

        if ($this->zone?->getName() !== $name) {
            $this->zone = new DateTimeZone($name);
        }

        return $this->zone;
    }
}
