<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'serial-handler')]
final readonly class SerialHandler implements App
{
    public const string ENTITY = 'input_number.protocol_serial';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(self::ENTITY)->subscribe(function (StateChange $change): void {
            $this->ha->callService('script', 'record', ['value' => $change->to?->state]);

            $this->logger->info('Handled', ['value' => $change->to?->state]);
        });
    }

    public function dispose(): void {}
}
