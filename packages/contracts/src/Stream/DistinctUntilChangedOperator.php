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
final readonly class DistinctUntilChangedOperator implements StreamSource
{
    /**
     * @param StreamSource<T> $source
     * @param Closure(T): mixed $key
     * @param Closure(T): string $partition
     */
    public function __construct(
        private StreamSource $source,
        private Closure $key,
        private Closure $partition,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $key = $this->key;
        $partition = $this->partition;
        /** @var array<string, mixed> $previousByPartition */
        $previousByPartition = [];

        return $this->source->attach($scope, static function (mixed $event) use ($key, $partition, $downstream, &$previousByPartition): void {
            $current = $key($event);
            $partitionKey = $partition($event);

            if (\array_key_exists($partitionKey, $previousByPartition) && $previousByPartition[$partitionKey] === $current) {
                return;
            }

            $previousByPartition[$partitionKey] = $current;

            $downstream($event);
        });
    }
}
