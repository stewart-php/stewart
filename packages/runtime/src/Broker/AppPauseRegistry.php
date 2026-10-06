<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final class AppPauseRegistry
{
    /** @var array<string, AppId> */
    private array $configPausedAppIds = [];

    /** @var array<string, AppPause> */
    private array $pauses = [];

    public function __construct(
        AppDefinitionCollection $enabledApps,
        private readonly DaemonStartTime $startTime,
    ) {
        foreach ($enabledApps->filter(static fn(AppDefinition $app): bool => $app->startsPaused) as $app) {
            $this->configPausedAppIds[$app->id->value] = $app->id;
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

        unset($this->configPausedAppIds[$appId->value], $this->pauses[$appId->value]);

        return true;
    }

    public function isPaused(AppId $appId): bool
    {
        return isset($this->configPausedAppIds[$appId->value]) || isset($this->pauses[$appId->value]);
    }

    public function findPause(AppId $appId): ?AppPause
    {
        if (isset($this->configPausedAppIds[$appId->value])) {
            // A config pause holds from daemon start, which is recorded after this registry is built.
            return new AppPause($appId, $this->startTime->getStartedAt(), AppPauseSource::Config);
        }

        return $this->pauses[$appId->value] ?? null;
    }

    public function listPausedAppIds(): AppIdCollection
    {
        return AppIdCollection::fromIds([
            ...array_values($this->configPausedAppIds),
            ...array_map(static fn(AppPause $pause): AppId => $pause->appId, array_values($this->pauses)),
        ]);
    }
}
