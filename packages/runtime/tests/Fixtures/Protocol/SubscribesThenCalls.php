<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'subscribes-then-calls')]
final readonly class SubscribesThenCalls implements App
{
    public const string ENTITY = 'input_boolean.protocol_test';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(self::ENTITY)->subscribe(function (StateChange $change): void {
            $this->logger->info('Handled', ['to' => $change->to?->state]);
        });

        $this->ha->callService('light', 'turn_on');
        $this->logger->info('Initialize returned');
    }

    public function dispose(): void {}
}
