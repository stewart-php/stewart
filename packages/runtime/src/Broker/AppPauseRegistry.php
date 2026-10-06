<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final class AppPauseRegistry
{
    /** @var array<string, AppPause> */
    private array $pauses = [];

    public function __construct(AppDefinitionCollection $enabledApps, Clock $clock)
    {
        foreach ($enabledApps->filter(static fn(AppDefinition $app): bool => $app->startsPaused) as $app) {
            $this->pauses[$app->id->value] = new AppPause($app->id, $clock->getNow(), AppPauseSource::Config);
        }
    }

    public function pauseApp(AppPause $pause): bool
    {
        if ($this->isPaused($pause->appId)) {
            return false;
        }

        $this->pauses[$pause->appId->value] = $pause;

        return true;
    }

    public function resumeApp(AppId $appId): bool
    {
        if (!$this->isPaused($appId)) {
            return false;
        }

        unset($this->pauses[$appId->value]);

        return true;
    }

    public function isPaused(AppId $appId): bool
    {
        return isset($this->pauses[$appId->value]);
    }

    public function findPause(AppId $appId): ?AppPause
    {
        return $this->pauses[$appId->value] ?? null;
    }

    public function listPausedAppIds(): AppIdCollection
    {
        return AppIdCollection::fromIds(array_map(static fn(AppPause $pause): AppId => $pause->appId, array_values($this->pauses)));
    }
}
