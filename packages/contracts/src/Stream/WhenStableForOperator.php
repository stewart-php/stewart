<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
 * @implements StreamSource<StateChange>
 */
final readonly class WhenStableForOperator implements StreamSource
{
    /**
     * @param StreamSource<StateChange> $source
     * @throws TimeException
     */
    public function __construct(
        private Timers $timers,
        private StreamSource $source,
        private Duration $window,
    ) {
        $window->requireAtLeastOneMillisecond('whenStableFor');
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        /** @var PendingEmissions<StateChange> $pendings */
        $pendings = new PendingEmissions($this->timers, $this->window, $scope, $scope->claimStage(), $downstream);

        $scope->onTeardown($pendings->disarmAll(...));

        return $this->source->attach($scope, static function (StateChange $change) use ($pendings): void {
            if (!$change->hasStateChanged()) {
                return;
            }

            $pendings->hold($change->entityId->value, $change);
            $pendings->arm($change->entityId->value);
        });
    }
}
