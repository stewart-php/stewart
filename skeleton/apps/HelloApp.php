<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'hello')]
final class HelloApp implements App
{
    public function __construct(
        private readonly HaContext $ha,
        private readonly LoggerInterface $logger,
        private readonly string $watch = 'sun.sun',
    ) {}

    public function initialize(): void
    {
        $this->logger->info('Hello from Stewart', ['entities' => \count($this->ha->listStates()), 'watching' => $this->watch]);

        $this->ha->watchStateChanges($this->watch)->subscribe(function (StateChange $change): void {
            $this->logger->info('State changed', ['entity' => $change->entityId->value, 'from' => $change->from?->state, 'to' => $change->to?->state]);
        });
    }

    public function dispose(): void {}
}
