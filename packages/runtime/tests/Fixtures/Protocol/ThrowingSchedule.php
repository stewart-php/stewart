<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use RuntimeException;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'throwing-schedule')]
final readonly class ThrowingSchedule implements App
{
    public const string FAILURE = 'schedule blew up';

    public function __construct(private Scheduler $scheduler) {}

    public function initialize(): void
    {
        $this->scheduler->runEvery(Duration::seconds(1), static function (): void {
            throw new RuntimeException(self::FAILURE);
        });
    }

    public function dispose(): void {}
}
