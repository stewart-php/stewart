<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class SignalHandlers
{
    /** @var list<string> */
    private array $watchers = [];

    public function __construct(
        private readonly BrokerRun $run,
        private readonly LoggerInterface $logger,
    ) {}

    public function install(): void
    {
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            try {
                $this->watchers[] = EventLoop::unreference(EventLoop::onSignal($signal, $this->handleStopSignal(...)));
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
}
