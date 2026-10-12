<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Support\Wire\TriggerSpec;

#[IpcMessage(tag: 'subscribe_trigger')]
final readonly class SubscribeTrigger implements WorkerMessage
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public ResourceScope $scope,
        public TriggerSpec $trigger,
    ) {}
}
