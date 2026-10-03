<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Connection\ConnectionRestored;
use Stewart\Contracts\HaContext;

#[Automation(id: 'outage-watcher')]
final readonly class OutageWatcher implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchConnection()->subscribe(function (ConnectionEvent $event): void {
            match (true) {
                $event instanceof ConnectionLost => $this->logger->warning('Home Assistant is gone', ['reason' => $event->reason]),
                $event instanceof ConnectionRestored => $this->logger->info('State resynced', [
                    'entities' => $event->entityCount,
                    'outage' => (string) $event->outage,
                    'reconstructed' => $event->reconstructedChanges,
                ]),
                default => null,
            };
        });
    }

    public function dispose(): void {}
}
