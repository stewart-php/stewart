<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;

final readonly class AppPauseService
{
    public function __construct(
        private AppCatalog $apps,
        private AppPauseRegistry $pausedApps,
        private WorkerSlotRegistry $slots,
        private LoggerInterface $logger,
    ) {}

    /** @throws AppException */
    public function pauseApp(AppId $appId): bool
    {
        $this->assertAppLoaded($appId);

        if (!$this->pausedApps->pauseApp($appId)) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App paused', ['app' => $appId->value]);

        return true;
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId): bool
    {
        $this->assertAppLoaded($appId);

        if (!$this->pausedApps->resumeApp($appId)) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App resumed', ['app' => $appId->value]);

        return true;
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
