<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\StreamSource;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;

/**
 * @template T
 * @implements StreamSource<T>
 */
final readonly class DispatchSource implements StreamSource
{
    public function __construct(
        private LocalDispatcher $dispatcher,
        private ResourceScope $scope,
        private SubscriptionKind $kind,
        private Selector $selector,
        private ?TriggerSpec $trigger = null,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        return $this->dispatcher->register($this->scope, $this->kind, $this->selector, $scope, $downstream, $this->trigger);
    }
}
