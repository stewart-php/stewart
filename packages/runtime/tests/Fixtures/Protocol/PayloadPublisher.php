<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\TopicEvent;

#[Automation(id: 'payload-publisher')]
final readonly class PayloadPublisher implements App
{
    public function __construct(private HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->publish('protocol.bad', ['nested' => [new TopicEvent('x', null, new AppId('y'), Instant::fromEpochMicroseconds(0))]]);
    }

    public function dispose(): void {}
}
