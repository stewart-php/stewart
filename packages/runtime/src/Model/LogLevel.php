<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /** @return list<self> */
    public static function configurable(): array
    {
        return [self::Debug, self::Info, self::Notice, self::Warning, self::Error];
    }

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    public function isBelow(self $threshold): bool
    {
        return $this->severity() < $threshold->severity();
    }
}
