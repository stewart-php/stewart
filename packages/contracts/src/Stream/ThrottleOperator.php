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
final readonly class ThrottleOperator implements StreamSource
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
        $window->requireAtLeastOneMillisecond('throttle');
    }

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $timers = $this->timers;
        $windowLength = $this->window;
        $edge = $this->edge;
        $partition = $this->partition;
        $stage = $scope->claimStage();
        /** @var array<string, ThrottleWindow<T>> $windows */
        $windows = [];

        $scope->onTeardown(static function () use (&$windows): void {
            foreach ($windows as $window) {
                $window->cancelWindowTimer();
            }
        });

        return $this->source->attach($scope, static function (mixed $event) use (&$windows, $partition, $timers, $windowLength, $scope, $stage, $edge, $downstream): void {
            $key = $partition($event);
            /** @var ThrottleWindow<T> $window */
            $window = $windows[$key] ??= new ThrottleWindow($timers, $windowLength, $scope, $stage, $downstream, static function () use (&$windows, $key): void {
                unset($windows[$key]);
            });

            if ($window->isOpen()) {
                if ($edge->emitsTrailing()) {
                    $window->holdEvent($event);
                }

                return;
            }

            $leads = $edge->emitsLeading();

            if (!$leads && $edge->emitsTrailing()) {
                $window->holdEvent($event);
            }

            $window->openWindow();

            if ($leads) {
                $downstream($event);
            }
        });
    }
}
