<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

#[IpcMessage(tag: 'subscribe')]
final readonly class Subscribe implements WorkerMessage
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public ResourceScope $scope,
        public SubscriptionKind $kind,
        public Selector $selector,
    ) {}
}
