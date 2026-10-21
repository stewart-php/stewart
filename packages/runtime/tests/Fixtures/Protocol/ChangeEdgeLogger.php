<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'change-edge-logger')]
final readonly class ChangeEdgeLogger implements App
{
    public const string MESSAGE = 'Change received';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
        private string $watch,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->watch)->subscribe(function (StateChange $change): void {
            $this->logger->info(self::MESSAGE, [
                'entity_id' => $change->entityId->value,
                'from' => $change->from?->state,
                'to' => $change->to?->state,
                'is_new' => $change->isNew(),
            ]);
        });
    }
}
