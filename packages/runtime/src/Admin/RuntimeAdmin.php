<?php

declare(strict_types=1);

namespace Stewart\Runtime\Admin;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\AppPauseResetOutcome;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerRestartOutcome;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Exception\WorkerException;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

final readonly class RuntimeAdmin
{
    public function __construct(
        private AppPauseService $pauses,
        private AppStatusBuilder $appStatuses,
        private WorkerSlotCollection $workerSlots,
        private WorkerPool $pool,
    ) {}

    /** @return list<AppStatus> */
    public function listHostedAppStatuses(): array
    {
        return $this->appStatuses->buildHostedAppStatuses();
    }

    /** @throws AppException */
    public function requireAppStatus(AppId $appId): AppStatus
    {
        $this->pauses->assertAppLoaded($appId);

        return $this->appStatuses->findAppStatus($appId) ?? throw AppException::unknown($appId);
    }

    /** @throws AppException */
    public function pauseApp(AppId $appId, AppPauseSource $source): CommandResult
    {
        $outcome = $this->pauses->pauseApp($appId, $source);
        $message = \sprintf($outcome->changed ? 'App %s paused.' : 'App %s was already paused.', $appId);

        return new CommandResult($outcome->changed, $message, $this->findChangeWarning($outcome));
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId, AppPauseSource $source): CommandResult
    {
        $outcome = $this->pauses->resumeApp($appId, $source);
        $message = \sprintf($outcome->changed ? 'App %s resumed.' : 'App %s was not paused.', $appId);

        return new CommandResult($outcome->changed, $message, $this->findChangeWarning($outcome));
    }

    /** @throws AppException|StoreException */
    public function resetApp(AppId $appId, AppPauseSource $source): CommandResult
    {
        $outcome = $this->pauses->resetApp($appId, $source);

        return new CommandResult($outcome->overrideRemoved, $this->describeResetOutcome($appId, $outcome), $this->findResetWarning($outcome));
    }

    /** @throws WorkerException */
    public function restartWorker(string $workerId): CommandResult
    {
        $slot = $this->workerSlots->requireSlotById($workerId);
        $outcome = $this->pool->restartWorker($slot);

        return new CommandResult($outcome === WorkerRestartOutcome::Begun, $outcome->describeForWorker($slot->workerId), null);
    }

    private function findChangeWarning(AppPauseOutcome $outcome): ?string
    {
        return $outcome->persistence->findWarning();
    }

    private function describeResetOutcome(AppId $appId, AppPauseResetOutcome $outcome): string
    {
        if (!$outcome->overrideRemoved) {
            return \sprintf('App %s had no pause override.', $appId);
        }

        return \sprintf(match ($outcome->stateAfter) {
            AppStateAfterReset::PausedByConfig => 'App %s pause override removed; config keeps it paused.',
            AppStateAfterReset::Running => 'App %s pause override removed; it is running.',
            AppStateAfterReset::NotLoaded => 'App %s pause override removed.',
        }, $appId);
    }

    private function findResetWarning(AppPauseResetOutcome $outcome): ?string
    {
        return $outcome->overrideRemoved || $outcome->persistence !== AppPauseOverridePersistence::NotConfigured ? $outcome->persistence->findWarning() : null;
    }
}
