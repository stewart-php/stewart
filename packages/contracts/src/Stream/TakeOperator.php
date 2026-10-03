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
final readonly class TakeOperator implements StreamSource
{
    /** @param StreamSource<T> $source */
    public function __construct(
        private StreamSource $source,
        private int $count,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $remaining = $this->count;

        if ($remaining <= 0) {
            $scope->close();

            return CancelledSubscription::createUnique();
        }

        $stage = $scope->claimStage();

        return $this->source->attach($scope, static function (mixed $event) use ($scope, $stage, $downstream, &$remaining): void {
            if ($remaining <= 0) {
                return;
            }

            --$remaining;

            try {
                $downstream($event);
            } finally {
                if ($remaining <= 0) {
                    $scope->closeWhenDownstreamSettles($stage);
                }
            }
        });
    }
}
