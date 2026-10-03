<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Subscription;

/**
 * @internal
 * @template T
 * @implements StreamSource<T>
 */
final readonly class FilterOperator implements StreamSource
{
    /**
     * @param StreamSource<T> $source
     * @param Closure(T): bool $predicate
     */
    public function __construct(
        private StreamSource $source,
        private Closure $predicate,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $predicate = $this->predicate;

        return $this->source->attach($scope, static function (mixed $event) use ($predicate, $downstream): void {
            if ($predicate($event)) {
                $downstream($event);
            }
        });
    }
}
