<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'waiting-initialize')]
final class WaitingInitializeApp implements App
{
    /** @var list<string> */
    public private(set) array $seen = [];

    public private(set) ?bool $ready = null;

    public function __construct(private readonly HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('sensor.outside_temperature')->subscribe(function (StateChange $change): void {
            $this->seen[] = (string) $change->to?->state;
        });
        $this->ready = $this->ha->waitForState('input_boolean.ready', 'on', Duration::seconds(10)) !== null;
    }
}
