<?php

declare(strict_types=1);

namespace Stewart\Support\Stream;

use Closure;
use Stewart\Contracts\Subscription;

/**
 * @internal
 * @template T
 */
interface StreamSource
{
    /** @param Closure(T): void $downstream */
    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription;
}
