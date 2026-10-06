<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\AppPauseSource;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;

final readonly class AppPauseService
{
    public function __construct(
        private AppCatalog $apps,
        private AppPauseRegistry $pausedApps,
        private WorkerSlotRegistry $slots,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /** @throws AppException */
    public function pauseApp(AppId $appId, AppPauseSource $source): bool
    {
        $this->assertAppLoaded($appId);

        if (!$this->pausedApps->pauseApp(new AppPause($appId, $this->clock->getNow(), $source))) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App paused', ['app' => $appId->value, 'source' => $source->value]);

        return true;
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId, AppPauseSource $source): bool
    {
        $this->assertAppLoaded($appId);

        if (!$this->pausedApps->resumeApp($appId)) {
            return false;
        }

        $this->broadcastPausedApps();
        $this->logger->info('App resumed', ['app' => $appId->value, 'source' => $source->value]);

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
