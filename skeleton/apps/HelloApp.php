<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'hello')]
final readonly class HelloApp implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
        private string $watch = 'sun.sun',
    ) {}

    public function initialize(): void
    {
        $entities = \count($this->ha->listStates());
        $this->logger->info('Hello from Stewart', ['entities' => $entities, 'watching' => $this->watch]);

        // A fixed ID replaces the notification on every start instead of adding another one.
        $this->ha->callService('persistent_notification', 'create', [
            'notification_id' => 'stewart_hello',
            'title' => 'Hello from Stewart',
            'message' => \sprintf('Stewart is connected and can see %d entities.', $entities),
        ]);

        $this->ha->watchStateChanges($this->watch)->subscribe(function (StateChange $change): void {
            $this->logger->info('State changed', ['entity' => $change->entityId->value, 'from' => $change->from?->state, 'to' => $change->to?->state]);
        });
    }
}
