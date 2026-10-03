<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Revolt\EventLoop;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'lingering-watcher')]
final readonly class LingeringWatcher implements App
{
    private const float KEEPS_PROCESS_ALIVE_SECONDS = 30;

    public function initialize(): void
    {
        EventLoop::delay(self::KEEPS_PROCESS_ALIVE_SECONDS, static function (): void {});
    }

    public function dispose(): void {}
}
