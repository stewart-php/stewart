<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use RuntimeException;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

#[Automation(id: 'throwing-timer')]
final readonly class ThrowingTimer implements App
{
    public const string FAILURE = 'timer blew up';

    public function __construct(private Timers $timers) {}

    public function initialize(): void
    {
        $this->timers->startTimer(Duration::milliseconds(10), static function (): void {
            throw new RuntimeException(self::FAILURE);
        });
    }

    public function dispose(): void {}
}
