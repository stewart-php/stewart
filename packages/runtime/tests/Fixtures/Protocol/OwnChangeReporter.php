<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'own-change-reporter')]
final class OwnChangeReporter implements App
{
    public const string LIGHT = 'light.hall';

    private ?EventContext $lastCall = null;

    public function __construct(
        private readonly HaContext $ha,
        private readonly StewartIdentity $identity,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(self::LIGHT)->subscribe(function (StateChange $change): void {
            $this->ha->callService('script', 'record', [
                'by_last_call' => $this->lastCall !== null && $change->wasCausedBy($this->lastCall),
                'by_stewart' => $this->identity->wasCausedByStewart($change),
            ]);
        });

        $this->lastCall = $this->ha->callService('light', 'turn_on', target: ServiceTarget::forEntities(self::LIGHT));
    }

    public function dispose(): void {}
}
