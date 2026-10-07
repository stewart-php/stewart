<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\State\CurrentStateReader;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Subscription;

/**
 * @internal
 * @implements StreamSource<StateChange>
 */
final readonly class StartWithCurrentStateOperator implements StreamSource
{
    /** @param StreamSource<StateChange> $source */
    public function __construct(
        private StreamSource $source,
        private CurrentStateReader $currentStates,
    ) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $subscription = $this->source->attach($scope, $downstream);

        foreach ($this->currentStates->readCurrentStates()->sortedByEntityId() as $state) {
            $seed = StateChange::fromCurrentState($state);
            $scope->emit(static function () use ($downstream, $seed): void {
                $downstream($seed);
            });
        }

        return $subscription;
    }
}
