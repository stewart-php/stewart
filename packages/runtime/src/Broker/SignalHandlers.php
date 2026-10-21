<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadRequestOutcome;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;

final class SignalHandlers
{
    /** @var list<string> */
    private array $watchers = [];

    public function __construct(
        private readonly BrokerRun $run,
        private readonly ReloadCoordinator $reloads,
        private readonly LoggerInterface $logger,
    ) {}

    public function install(): void
    {
        /** @var array<int, Closure(): void> $handlers */
        $handlers = [
            \SIGTERM => $this->handleStopSignal(...),
            \SIGINT => $this->handleStopSignal(...),
            \SIGHUP => $this->handleReloadSignal(...),
        ];

        foreach ($handlers as $signal => $handler) {
            try {
                $this->watchers[] = EventLoop::unreference(EventLoop::onSignal($signal, $handler));
            } catch (EventLoop\UnsupportedFeatureException) {
                $this->logger->warning('Signal handling unavailable; shutdown will not be graceful.');

                return;
            }
        }
    }

    public function removeAll(): void
    {
        foreach ($this->watchers as $watcher) {
            EventLoop::cancel($watcher);
        }

        $this->watchers = [];
    }

    private function handleStopSignal(): void
    {
        if ($this->run->isStopping()) {
            $this->run->killWorkersNow();

            return;
        }

        $this->run->stop('signal');
    }

    private function handleReloadSignal(): void
    {
        $outcome = $this->reloads->requestReload(ReloadTrigger::Signal);

        if ($outcome !== ReloadRequestOutcome::Started) {
            $this->logger->info('Reload signal ignored', ['reason' => $outcome->describeOutcome()]);
        }
    }
}
