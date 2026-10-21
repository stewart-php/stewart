<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Instant;
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

    /** @var array<string, Instant> */
    private array $configPausedSince = [];

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

    // A config pause a reload adds holds from then; one held since the start keeps its start.
    public function replaceConfigPauses(AppDefinitionCollection $enabledApps, Instant $since): void
    {
        $pausedAppIds = [];
        $pausedSince = [];

        foreach ($enabledApps->filter(static fn(AppDefinition $app): bool => $app->startsPaused) as $app) {
            $id = $app->id->value;
            $pausedAppIds[$id] = $app->id;
            $kept = isset($this->configPausedAppIds[$id]) ? $this->configPausedSince[$id] ?? null : $since;

            if ($kept !== null) {
                $pausedSince[$id] = $kept;
            }
        }

        $this->configPausedAppIds = $pausedAppIds;
        $this->configPausedSince = $pausedSince;
    }

    public function forgetOverridesExcept(AppIdCollection $appIds): void
    {
        $this->overrides = array_filter($this->overrides, static fn(AppPauseOverride $override): bool => $appIds->containsId($override->appId));
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
            return new AppPause($appId, $this->configPausedSince[$appId->value] ?? $this->startTime->getStartedAt(), AppPauseSource::Config);
        }

        return null;
    }

    public function findConfigPauseOverride(AppId $appId): ?AppPauseOverride
    {
        $override = $this->findOverride($appId);

        return $override !== null && !$override->paused && isset($this->configPausedAppIds[$appId->value]) ? $override : null;
    }

    public function listPausedAppIds(): AppIdCollection
    {
        return AppIdCollection::fromList([
            ...array_values(array_diff_key($this->configPausedAppIds, $this->overrides)),
            ...array_map(
                static fn(AppPauseOverride $override): AppId => $override->appId,
                array_values(array_filter($this->overrides, static fn(AppPauseOverride $override): bool => $override->paused)),
            ),
        ]);
    }
}
