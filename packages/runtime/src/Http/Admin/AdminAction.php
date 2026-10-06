<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

enum AdminAction
{
    case ListApps;
    case ShowApp;

    /** @return non-empty-list<non-empty-string> */
    public function listAllowedMethods(): array
    {
        return match ($this) {
            self::ListApps, self::ShowApp => ['GET', 'HEAD'],
        };
    }
}
