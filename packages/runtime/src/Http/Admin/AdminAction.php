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

    /** @return non-empty-list<non-empty-string> */
    public function listAllowedMethods(): array
    {
        return match ($this) {
            self::ListApps, self::ShowApp => ['GET', 'HEAD'],
            self::PauseApp, self::ResumeApp, self::ResetApp => ['POST'],
        };
    }
}
