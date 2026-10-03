<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Subscription;

/** @template T */
interface StreamSource
{
    /** @param Closure(T): void $downstream */
    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription;
}
