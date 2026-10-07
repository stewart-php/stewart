<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
 * @implements StreamSource<StateChange>
 */
final readonly class ThresholdCrossingOperator implements StreamSource
{
    /**
     * @param StreamSource<StateChange> $source
     * @throws StateException|TimeException
     */
    public function __construct(
        private Timers $timers,
        private StreamSource $source,
        private ThresholdDirection $direction,
        private float $threshold,
        private ?Duration $window,
        private ?string $attribute,
        private float $hysteresis,
    ) {
        $window?->requireAtLeastOneMillisecond($direction->describeUsage());

        if (!is_finite($threshold)) {
            throw StateException::thresholdNotFinite($direction->describeUsage(), $threshold);
        }

        if (is_nan($hysteresis) || $hysteresis < 0.0) {
            throw StateException::thresholdHysteresisNegative($direction->describeUsage(), $hysteresis);
        }
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $direction = $this->direction;
        $threshold = $this->threshold;
        $hysteresis = $this->hysteresis;
        $attribute = $this->attribute;
        $latches = new CrossingLatches();

        $readValue = static fn(?EntityState $state): ?float => $attribute === null
            ? $state?->getStateAsFloat()
            : $state?->getFloatAttribute($attribute);

        $deliver = static function (StateChange $change) use ($latches, $downstream): void {
            $latches->recordFired($change->entityId->value);
            $downstream($change);
        };

        /** @var PendingEmissions<StateChange>|null $pendings */
        $pendings = null;

        if ($this->window !== null) {
            $pendings = new PendingEmissions($this->timers, $this->window, $scope, $scope->claimStage(), $deliver);
            $scope->onTeardown($pendings->disarmAll(...));
        }

        return $this->source->attach($scope, static function (StateChange $change) use ($direction, $threshold, $hysteresis, $latches, $readValue, $deliver, $pendings): void {
            $key = $change->entityId->value;
            $value = $readValue($change->to);

            if ($value === null) {
                $latches->forget($key);
                $pendings?->disarm($key);

                return;
            }

            if (!$latches->isKnown($key)) {
                $previous = $readValue($change->from);
                $primingValue = $previous ?? $value;

                $direction->isBeyond($primingValue, $threshold) ? $latches->recordFired($key) : $latches->recordArmed($key);

                if ($previous === null) {
                    return;
                }
            }

            if ($latches->isFired($key)) {
                if ($direction->hasReturned($value, $threshold, $hysteresis)) {
                    $latches->recordArmed($key);
                }

                return;
            }

            if (!$direction->isBeyond($value, $threshold)) {
                $pendings?->disarm($key);

                return;
            }

            if ($pendings === null) {
                $deliver($change);

                return;
            }

            if (!$pendings->isArmed($key)) {
                $pendings->hold($key, $change);
                $pendings->arm($key);
            }
        });
    }
}
