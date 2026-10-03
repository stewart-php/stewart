<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\HaContext;

#[Automation(id: 'event-logger')]
final readonly class EventLogger implements App
{
    public const string EVENT = 'protocol_event';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchEvents(self::EVENT)->subscribe(function (HaEvent $event): void {
            $this->logger->info('Event received', ['event_type' => $event->type, 'data' => json_encode($event->data)]);
        });
    }

    public function dispose(): void {}
}
