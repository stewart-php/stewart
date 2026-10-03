<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'dispose-caller')]
final readonly class DisposeCaller implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void {}

    public function dispose(): void
    {
        $this->ha->callService('light', 'turn_off');

        $this->logger->info('Dispose call finished');
    }
}
