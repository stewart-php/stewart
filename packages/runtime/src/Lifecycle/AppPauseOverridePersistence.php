<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppPauseOverridePersistence
{
    case Stored;
    case NotConfigured;
    case Failed;

    public function findWarning(): ?string
    {
        return match ($this) {
            self::Stored => null,
            self::NotConfigured => 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.',
            self::Failed => 'Not saved: the store did not take the change, so it lasts until the daemon restarts; run the command again once the store is back.',
        };
    }
}
