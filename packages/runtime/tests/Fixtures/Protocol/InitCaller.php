<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'init-caller')]
final readonly class InitCaller implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->callService('light', 'turn_on');

        $this->logger->info('Initialize call finished', ['service' => 'light.turn_on']);
    }

    public function dispose(): void {}
}
