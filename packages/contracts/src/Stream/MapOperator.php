<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Subscription;

/**
 * @internal
 * @template TIn
 * @template TOut
 * @implements StreamSource<TOut>
 */
final readonly class MapOperator implements StreamSource
{
    /**
     * @param StreamSource<TIn> $source
     * @param Closure(TIn): TOut $mapper
     */
    public function __construct(
        private StreamSource $source,
        private Closure $mapper,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $mapper = $this->mapper;

        return $this->source->attach($scope, static function (mixed $event) use ($mapper, $downstream): void {
            $downstream($mapper($event));
        });
    }
}
