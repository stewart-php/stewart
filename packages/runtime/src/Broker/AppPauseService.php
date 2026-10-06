<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;

final readonly class AppPauseService
{
    public function __construct(
        private AppPauseRegistry $pausedApps,
        private WorkerSlotRegistry $slots,
        private LoggerInterface $logger,
    ) {}

    public function pauseApp(AppId $appId): bool
    {
        if (!$this->pausedApps->pauseApp($appId)) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App paused', ['app' => $appId->value]);

        return true;
    }

    public function resumeApp(AppId $appId): bool
    {
        if (!$this->pausedApps->resumeApp($appId)) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App resumed', ['app' => $appId->value]);

        return true;
    }

    private function broadcastPausedApps(): void
    {
        $this->slots->broadcast(new PausedAppsChanged(AppIdsFragment::fromCollection($this->pausedApps->listPausedAppIds())));
    }
}
