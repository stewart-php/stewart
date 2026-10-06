<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;
use Throwable;

use function Amp\async;

final class RegistryRefresher
{
    private const array REFRESHING_EVENT_TYPES = [
        'area_registry_updated',
        'floor_registry_updated',
        'label_registry_updated',
        'device_registry_updated',
        'entity_registry_updated',
    ];

    private const int QUIET_PERIOD_MILLISECONDS = 1000;

    private ?TimerHandle $pendingRefresh = null;

    private bool $refreshing = false;

    private bool $refreshRequestedWhileRefreshing = false;

    public function __construct(
        private readonly HaSession $session,
        private readonly WorkerSlotRegistry $slots,
        private readonly Timers $timers,
        private readonly LoggerInterface $logger,
    ) {}

    public function scheduleRefreshFor(HaEvent $event): void
    {
        if (!\in_array($event->type, self::REFRESHING_EVENT_TYPES, true)) {
            return;
        }

        $this->pendingRefresh?->cancel();
        $this->pendingRefresh = $this->timers->startTimer(Duration::milliseconds(self::QUIET_PERIOD_MILLISECONDS), $this->startRefresh(...));
    }

    private function startRefresh(): void
    {
        $this->pendingRefresh = null;

        if ($this->refreshing) {
            $this->refreshRequestedWhileRefreshing = true;

            return;
        }

        $this->refreshing = true;

        async(function (): void {
            try {
                do {
                    $this->refreshRequestedWhileRefreshing = false;
                    $this->refreshAndBroadcast();
                } while ($this->refreshRequestedWhileRefreshing);
            } catch (Throwable $e) {
                $this->logger->error('Failed refreshing the Home Assistant registry', ['exception' => $e]);
            } finally {
                $this->refreshing = false;
            }
        })->ignore();
    }

    private function refreshAndBroadcast(): void
    {
        if ($this->session->refreshRegistry()) {
            $this->slots->broadcast($this->session->snapshotRegistry()->toRegistrySnapshot());
        }
    }
}
