<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'switch-on-watcher')]
final readonly class SwitchOnWatcher implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
        private string $watch,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->watch)
            ->filter(static fn(StateChange $change): bool => $change->to?->state === 'on')
            ->subscribe(function (StateChange $change): void {
                $this->logger->info('Switched on', ['entity' => $change->entityId->value]);
            });
    }
}
