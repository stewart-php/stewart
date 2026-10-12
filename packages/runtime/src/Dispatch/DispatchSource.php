<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Subscription;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Support\Stream\StreamSource;
use Stewart\Support\Stream\SubscriptionScope;
use Stewart\Support\Wire\TriggerSpec;

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
        private ?EntityFilter $entityFilter = null,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        return $this->dispatcher->register($this->scope, $this->kind, $this->selector, $scope, $downstream, $this->trigger, $this->entityFilter);
    }
}
