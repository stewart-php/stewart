<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'operator-chain')]
final readonly class OperatorChain implements App
{
    public function __construct(private HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('input_boolean.protocol_test')
            ->whenChangedTo('on')
            ->debounce(Duration::milliseconds(20))
            ->subscribe(function (StateChange $change): void {
                $this->ha->publish('operator.settled', ['state' => $change->to?->state]);
            });

        // A one-hour hold timer must not keep the worker from exiting.
        $this->ha->watchStateChanges('sensor.*')
            ->whenStableFor(Duration::hours(1))
            ->subscribe(function (StateChange $change): void {
                $this->ha->publish('operator.stable', ['entity' => $change->entityId]);
            });
    }

    public function dispose(): void {}
}
