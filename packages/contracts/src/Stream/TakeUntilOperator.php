<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Subscription;

/**
 * @internal
 * @template T
 * @implements StreamSource<T>
 */
final readonly class TakeUntilOperator implements StreamSource
{
    /**
     * @param StreamSource<T> $source
     * @param EventStream<mixed> $notifier
     */
    public function __construct(
        private StreamSource $source,
        private EventStream $notifier,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $watching = $this->notifier->subscribe(static function () use ($scope): void {
            $scope->close();
        });

        $scope->onTeardown($watching->unsubscribe(...));

        return $this->source->attach($scope, $downstream);
    }
}
