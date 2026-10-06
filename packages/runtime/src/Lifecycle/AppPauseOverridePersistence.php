<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppPauseOverridePersistence
{
    case Stored;
    case NotConfigured;
    case Failed;
}
