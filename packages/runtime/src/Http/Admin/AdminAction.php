<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

enum AdminAction: string
{
    case ListApps = 'list';
    case ShowApp = 'show';
    case PauseApp = 'pause';
    case ResumeApp = 'resume';
    case ResetApp = 'reset';
    case ShowMetrics = 'metrics';

    /** @return non-empty-list<non-empty-string> */
    public function listAllowedMethods(): array
    {
        return match ($this) {
            self::ListApps, self::ShowApp, self::ShowMetrics => ['GET', 'HEAD'],
            self::PauseApp, self::ResumeApp, self::ResetApp => ['POST'],
        };
    }
}
