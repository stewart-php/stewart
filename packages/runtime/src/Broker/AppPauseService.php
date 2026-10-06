<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\App\AppPauseResetOutcome;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

final readonly class AppPauseService
{
    public function __construct(
        private AppCatalog $apps,
        private AppPauseRegistry $pausedApps,
        private AppPauseOverrideStore $overrides,
        private WorkerSlotRegistry $slots,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /** @throws StoreException */
    public function restoreStoredOverrides(): void
    {
        $stored = $this->overrides->loadOverrides();
        $this->pausedApps->restoreOverrides($stored->filter(fn(AppPauseOverride $override): bool => $this->apps->enabled->find($override->appId) !== null));
        $unknown = $stored->filter(fn(AppPauseOverride $override): bool => !$this->apps->knownIds->containsId($override->appId));

        if ($unknown->count() > 0) {
            $this->logger->warning('Stored pause overrides name apps that no longer exist; stewart app:reset <app> removes them', [
                'apps' => implode(', ', $unknown->mapToList(static fn(AppPauseOverride $override): string => $override->appId->value)),
            ]);
        }
    }

    /** @throws AppException */
    public function pauseApp(AppId $appId, AppPauseSource $source): AppPauseOutcome
    {
        return $this->overridePause($appId, true, $source);
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId, AppPauseSource $source): AppPauseOutcome
    {
        return $this->overridePause($appId, false, $source);
    }

    /** @throws AppException|StoreException */
    public function resetApp(AppId $appId, AppPauseSource $source): AppPauseResetOutcome
    {
        if ($this->apps->enabled->find($appId) === null) {
            return $this->resetStoredOnlyApp($appId, $source);
        }

        $wasPaused = $this->pausedApps->isPaused($appId);
        $removed = $this->pausedApps->forgetOverride($appId);
        $paused = $this->pausedApps->isPaused($appId);

        if ($paused !== $wasPaused) {
            $this->broadcastPausedApps();
        }

        if ($removed) {
            $this->logger->info('App pause override removed', ['app' => $appId->value, 'source' => $source->value, 'paused' => $paused]);
        }

        $persistence = $this->overrides->removeOverride($appId);

        return new AppPauseResetOutcome($removed, $paused ? AppStateAfterReset::PausedByConfig : AppStateAfterReset::Running, $persistence);
    }

    /** @throws AppException */
    public function assertAppLoaded(AppId $appId): void
    {
        if ($this->apps->enabled->find($appId) === null) {
            throw $this->createNotLoadedException($appId);
        }
    }

    /** @throws AppException|StoreException */
    private function resetStoredOnlyApp(AppId $appId, AppPauseSource $source): AppPauseResetOutcome
    {
        if (!$this->overrides->hasOverride($appId)) {
            throw $this->createNotLoadedException($appId);
        }

        $persistence = $this->overrides->removeOverride($appId);
        $this->logger->info('App pause override removed', ['app' => $appId->value, 'source' => $source->value]);

        return new AppPauseResetOutcome(true, AppStateAfterReset::NotLoaded, $persistence);
    }

    /** @throws AppException */
    private function overridePause(AppId $appId, bool $paused, AppPauseSource $source): AppPauseOutcome
    {
        $this->assertAppLoaded($appId);
        $override = $this->createOverride($appId, $paused, $source);
        $changed = $this->pausedApps->recordOverride($override);

        if ($changed) {
            $this->broadcastPausedApps();
            $this->logger->info($paused ? 'App paused' : 'App resumed', ['app' => $appId->value, 'source' => $source->value]);
        }

        $persistence = $this->overrides->saveOverride($override);

        return new AppPauseOutcome($changed, $persistence);
    }

    private function createOverride(AppId $appId, bool $paused, AppPauseSource $source): AppPauseOverride
    {
        $existing = $this->pausedApps->findOverride($appId);

        if ($existing?->paused === $paused) {
            return $existing;
        }

        // Pinning a config pause keeps its start and source, so status still says why the app is paused.
        $configPause = $existing === null && $paused ? $this->pausedApps->findPause($appId) : null;

        return $configPause === null
            ? new AppPauseOverride($appId, $paused, $this->clock->getNow(), $source)
            : new AppPauseOverride($appId, true, $configPause->since, $configPause->source);
    }

    private function createNotLoadedException(AppId $appId): AppException
    {
        return $this->apps->knownIds->containsId($appId) ? AppException::disabled($appId) : AppException::unknown($appId);
    }

    private function broadcastPausedApps(): void
    {
        $this->slots->broadcast(new PausedAppsChanged(AppIdsFragment::fromCollection($this->pausedApps->listPausedAppIds())));
    }
}
