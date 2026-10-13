<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'daily-energy')]
final readonly class DailyEnergy implements App
{
    private const int DAYS_KEPT = 7;

    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private Store $store,
        private Clock $clock,
        private string $meter = 'sensor.energy_today',
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runDailyAt('23:55', function (): void {
            $today = $this->ha->getState($this->meter)?->getStateAsFloat();

            if ($today === null) {
                return;
            }

            $this->store->set($this->createDayKey(0), $today, Duration::hours(24 * self::DAYS_KEPT));
            $week = array_sum(array_map(fn(int $daysAgo): float => $this->store->getFloat($this->createDayKey($daysAgo)) ?? 0.0, range(0, self::DAYS_KEPT - 1)));

            $this->ha->callService('notify', 'notify', [
                'title' => 'Energy',
                'message' => \sprintf('Today %.1f kWh, last 7 days %.1f kWh.', $today, $week),
            ]);
        });
    }

    public function dispose(): void {}

    private function createDayKey(int $daysAgo): string
    {
        $day = $this->clock->getNow()->minus(Duration::hours(24 * $daysAgo))->toDateTime($this->clock->getTimeZone());

        return 'day:' . $day->format('Y-m-d');
    }
}
