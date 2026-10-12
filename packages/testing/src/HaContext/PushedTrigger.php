<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Support\Wire\TriggerSpec;

final readonly class PushedTrigger
{
    public function __construct(
        public TriggerSpec $spec,
        public TriggerEvent $event,
    ) {}
}
