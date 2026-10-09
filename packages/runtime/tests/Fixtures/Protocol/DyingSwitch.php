<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exposure\EntityExposure;

#[Automation(id: 'dying-switch')]
final readonly class DyingSwitch implements App
{
    public function __construct(private EntityExposure $entities) {}

    public function initialize(): void
    {
        $switch = $this->entities->exposeSwitch('fuse');
        $switch->watchCommands()->subscribe(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });
        $switch->setOff();
    }

    public function dispose(): void {}
}
