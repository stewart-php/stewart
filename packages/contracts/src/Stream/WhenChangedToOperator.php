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
final readonly class WhenChangedToOperator implements StreamSource
{
    /**
     * @param StreamSource<StateChange> $source
     * @throws TimeException
     */
    public function __construct(
        private Timers $timers,
        private StreamSource $source,
        private string $state,
        private ?Duration $window,
    ) {
        $window?->requireAtLeastOneMillisecond('whenChangedTo');
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $state = $this->state;
        $window = $this->window;

        if ($window === null) {
            return $this->source->attach($scope, static function (StateChange $change) use ($state, $downstream): void {
                if ($change->changedTo($state)) {
                    $downstream($change);
                }
            });
        }

        /** @var PendingEmissions<StateChange> $pendings */
        $pendings = new PendingEmissions($this->timers, $window, $scope, $scope->claimStage(), $downstream);

        $scope->onTeardown($pendings->disarmAll(...));

        return $this->source->attach($scope, static function (StateChange $change) use ($state, $pendings): void {
            $entityId = $change->entityId->value;

            if ($change->changedTo($state)) {
                $pendings->hold($entityId, $change);
                $pendings->arm($entityId);

                return;
            }

            if ($change->to?->state !== $state) {
                $pendings->disarm($entityId);
            }
        });
    }
}
