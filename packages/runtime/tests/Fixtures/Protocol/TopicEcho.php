<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Topic\TopicEvent;

#[Automation(id: 'topic-echo')]
final readonly class TopicEcho implements App
{
    public const string PATTERN = 'watch.*';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchTopic(self::PATTERN)->subscribe(function (TopicEvent $event): void {
            $this->logger->info('Topic received', ['topic' => $event->topic, 'from' => $event->publisher?->value]);
        });
    }

    public function dispose(): void {}
}
