<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'kitchen-watcher')]
final readonly class KitchenWatcher implements App
{
    public const string AREA = 'kitchen';

    public function __construct(private HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(EntityFilter::inArea(self::AREA))->subscribe(function (StateChange $change): void {
            $this->ha->callService('script', 'record', ['entity_id' => $change->entityId->value]);
        });
    }

    public function dispose(): void {}
}
