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
        private PreviousStateRule $previousStateRule,
    ) {
        $window?->requireAtLeastOneMillisecond('whenChangedTo');
    }

    public function withPreviousStateRule(PreviousStateRule $previousStateRule): self
    {
        return new self($this->timers, $this->source, $this->state, $this->window, $previousStateRule);
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $state = $this->state;
        $window = $this->window;
        $previousStateRule = $this->previousStateRule;

        if ($window === null) {
            return $this->source->attach($scope, static function (StateChange $change) use ($state, $previousStateRule, $downstream): void {
                if ($change->changedTo($state) && $previousStateRule->permits($change)) {
                    $downstream($change);
                }
            });
        }

        /** @var PendingEmissions<StateChange> $pendings */
        $pendings = new PendingEmissions($this->timers, $window, $scope, $scope->claimStage(), $downstream);

        $scope->onTeardown($pendings->disarmAll(...));

        return $this->source->attach($scope, static function (StateChange $change) use ($state, $previousStateRule, $pendings): void {
            $entityId = $change->entityId->value;

            if ($change->changedTo($state) && $previousStateRule->permits($change)) {
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
