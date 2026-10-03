<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use RuntimeException;
use Stewart\Contracts\App;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

final class BreaksOnInitialize implements App
{
    public function __construct(
        private readonly HaContext $ha,
        private readonly Scheduler $scheduler,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('light.hall')->subscribe(static function (): void {});
        $this->scheduler->runEvery(Duration::seconds(10), static function (): void {});

        throw new RuntimeException('initialize blew up');
    }

    public function dispose(): void {}
}
