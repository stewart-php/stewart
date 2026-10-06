<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Lifecycle\AppPauseSource;

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

    /** @throws AppException */
    private function overridePause(AppId $appId, bool $paused, AppPauseSource $source): AppPauseOutcome
    {
        $this->assertAppLoaded($appId);
        $existing = $this->pausedApps->findOverride($appId);
        $override = $existing?->paused === $paused ? $existing : new AppPauseOverride($appId, $paused, $this->clock->getNow(), $source);
        $changed = $this->pausedApps->recordOverride($override);
        $persistence = $this->overrides->saveOverride($override);

        if ($changed) {
            $this->broadcastPausedApps();
            $this->logger->info($paused ? 'App paused' : 'App resumed', ['app' => $appId->value, 'source' => $source->value]);
        }

        return new AppPauseOutcome($changed, $persistence);
    }

    private function assertAppLoaded(AppId $appId): void
    {
        if ($this->apps->enabled->find($appId) !== null) {
            return;
        }

        throw $this->apps->knownIds->containsId($appId) ? AppException::disabled($appId) : AppException::unknown($appId);
    }

    private function broadcastPausedApps(): void
    {
        $this->slots->broadcast(new PausedAppsChanged(AppIdsFragment::fromCollection($this->pausedApps->listPausedAppIds())));
    }
}
