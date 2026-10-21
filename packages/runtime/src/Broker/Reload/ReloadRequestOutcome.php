<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

enum ReloadRequestOutcome
{
    case Started;
    case AlreadyUnderway;
    case NotAccepting;

    public function describeOutcome(): string
    {
        return match ($this) {
            self::Started => 'Reload started; stewart status shows how it went.',
            self::AlreadyUnderway => 'A reload is already underway.',
            self::NotAccepting => 'Stewart is not running its apps yet, so there is nothing to reload.',
        };
    }
}
