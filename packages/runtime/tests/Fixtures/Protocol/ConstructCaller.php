<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'construct-caller')]
final readonly class ConstructCaller implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {
        $ha->callService('light', 'turn_on');

        $logger->info('Construct call finished', ['service' => 'light.turn_on']);
    }

    public function initialize(): void
    {
        $this->logger->info('Construct caller initialized');
    }

    public function dispose(): void {}
}
