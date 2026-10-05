<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'subscribe_trigger')]
final readonly class SubscribeTrigger implements WorkerMessage
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public ResourceScope $scope,
        public TriggerSpec $trigger,
    ) {}
}
