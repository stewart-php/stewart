<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\App\Collection\AppPauseOverrideCollection;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final class AppPauseRegistry
{
    /** @var array<string, AppId> */
    private array $configPausedAppIds = [];

    /** @var array<string, AppPauseOverride> */
    private array $overrides = [];

    public function __construct(
        AppDefinitionCollection $enabledApps,
        private readonly DaemonStartTime $startTime,
    ) {
        foreach ($enabledApps->filter(static fn(AppDefinition $app): bool => $app->startsPaused) as $app) {
            $this->configPausedAppIds[$app->id->value] = $app->id;
        }
    }

    public function restoreOverrides(AppPauseOverrideCollection $overrides): void
    {
        foreach ($overrides as $override) {
            $this->overrides[$override->appId->value] = $override;
        }
    }

    public function recordOverride(AppPauseOverride $override): bool
    {
        $changed = $this->isPaused($override->appId) !== $override->paused;

        if ($changed) {
            unset($this->overrides[$override->appId->value]);
        }

        $this->overrides[$override->appId->value] = $override;

        return $changed;
    }

    public function forgetOverride(AppId $appId): bool
    {
        $existed = isset($this->overrides[$appId->value]);
        unset($this->overrides[$appId->value]);

        return $existed;
    }

    public function findOverride(AppId $appId): ?AppPauseOverride
    {
        return $this->overrides[$appId->value] ?? null;
    }

    public function isPaused(AppId $appId): bool
    {
        return $this->findOverride($appId)->paused ?? isset($this->configPausedAppIds[$appId->value]);
    }

    public function findPause(AppId $appId): ?AppPause
    {
        $override = $this->findOverride($appId);

        if ($override !== null) {
            return $override->paused ? new AppPause($appId, $override->since, $override->source) : null;
        }

        if (isset($this->configPausedAppIds[$appId->value])) {
            // A config pause holds from daemon start, which is recorded after this registry is built.
            return new AppPause($appId, $this->startTime->getStartedAt(), AppPauseSource::Config);
        }

        return null;
    }

    public function listPausedAppIds(): AppIdCollection
    {
        return AppIdCollection::fromIds([
            ...array_values(array_diff_key($this->configPausedAppIds, $this->overrides)),
            ...array_map(
                static fn(AppPauseOverride $override): AppId => $override->appId,
                array_values(array_filter($this->overrides, static fn(AppPauseOverride $override): bool => $override->paused)),
            ),
        ]);
    }
}
