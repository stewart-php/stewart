<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
 * @template T
 * @implements StreamSource<T>
 */
final readonly class DebounceOperator implements StreamSource
{
    /**
     * @param StreamSource<T> $source
     * @param Closure(T): string $partition
     * @throws TimeException
     */
    public function __construct(
        private Timers $timers,
        private StreamSource $source,
        private Duration $window,
        private Edge $edge,
        private Closure $partition,
    ) {
        $window->requireAtLeastOneMillisecond('debounce');
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $edge = $this->edge;
        $partition = $this->partition;
        /** @var PendingEmissions<T> $pendings */
        $pendings = new PendingEmissions($this->timers, $this->window, $scope, $scope->claimStage(), $downstream);

        $scope->onTeardown($pendings->disarmAll(...));

        return $this->source->attach($scope, static function (mixed $event) use ($pendings, $partition, $edge, $downstream): void {
            $key = $partition($event);
            $leads = !$pendings->isArmed($key) && $edge->emitsLeading();

            if (!$leads && $edge->emitsTrailing()) {
                $pendings->hold($key, $event);
            }

            $pendings->arm($key);

            if ($leads) {
                $downstream($event);
            }
        });
    }
}
