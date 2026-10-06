<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;

final class AppPauseRegistry
{
    /** @var array<string, AppId> */
    private array $pausedApps = [];

    public function __construct(AppDefinitionCollection $enabledApps)
    {
        foreach ($enabledApps->filter(static fn(AppDefinition $app): bool => $app->startsPaused) as $app) {
            $this->pausedApps[$app->id->value] = $app->id;
        }
    }

    public function pauseApp(AppId $appId): bool
    {
        if ($this->isPaused($appId)) {
            return false;
        }

        $this->pausedApps[$appId->value] = $appId;

        return true;
    }

    public function resumeApp(AppId $appId): bool
    {
        if (!$this->isPaused($appId)) {
            return false;
        }

        unset($this->pausedApps[$appId->value]);

        return true;
    }

    public function isPaused(AppId $appId): bool
    {
        return isset($this->pausedApps[$appId->value]);
    }

    public function listPausedAppIds(): AppIdCollection
    {
        return AppIdCollection::fromIds(array_values($this->pausedApps));
    }
}
