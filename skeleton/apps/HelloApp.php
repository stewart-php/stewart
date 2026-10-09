<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\NumberMode;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorStateClass;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'hello')]
final class HelloApp implements App
{
    private ?ExposedSensor $changesSeen = null;

    private ?ExposedSwitch $counting = null;

    private ?ExposedNumber $step = null;

    private int $changeCount = 0;

    public function __construct(
        private readonly HaContext $ha,
        private readonly EntityExposure $entities,
        private readonly LoggerInterface $logger,
        private readonly string $watch = 'sun.sun',
    ) {}

    public function initialize(): void
    {
        $this->logger->info('Hello from Stewart', ['entities' => \count($this->ha->listStates()), 'watching' => $this->watch]);

        try {
            $this->changesSeen = $this->entities->exposeSensor('changes_seen', new SensorConfig(stateClass: SensorStateClass::TotalIncreasing, name: 'Changes seen', icon: 'mdi:counter'));
            $this->changesSeen->setValue($this->changeCount);
            $this->counting = $this->exposeCountingSwitch();
            $this->step = $this->exposeCountStep(paused: $this->counting->getValue() === false);
        } catch (ExposureException $e) {
            $this->stopExposing($e);
        }

        $this->ha->watchStateChanges($this->watch)->subscribe(function (StateChange $change): void {
            $this->logger->info('State changed', ['entity' => $change->entityId->value, 'from' => $change->from?->state, 'to' => $change->to?->state]);
            $this->countChange();
        });
    }

    public function dispose(): void {}

    private function exposeCountingSwitch(): ExposedSwitch
    {
        $counting = $this->entities->exposeSwitch('counting', new SwitchConfig(name: 'Counting', icon: 'mdi:counter'));

        if ($counting->getValue() === null) {
            $counting->setOn();
        }

        $counting->watchCommands()->subscribe(function (SwitchCommand $command): void {
            $this->logger->info('Counting changes turned ' . ($command->isTurnOn() ? 'on' : 'off'));

            try {
                $this->step?->updateConfig(self::createStepConfig(paused: !$command->isTurnOn()));
            } catch (ExposureException $e) {
                $this->stopExposing($e);
            }
        });

        return $counting;
    }

    private function exposeCountStep(bool $paused): ExposedNumber
    {
        $step = $this->entities->exposeNumber('step', self::createStepConfig($paused));

        if ($step->getValue() === null) {
            $step->setValue(1);
        }

        $step->watchCommands()->subscribe(function (NumberCommand $command): void {
            $this->logger->info('Each change now counts ' . $command->value);
        });

        return $step;
    }

    private static function createStepConfig(bool $paused): NumberConfig
    {
        return new NumberConfig(min: 1, max: 5, mode: NumberMode::Box, name: 'Step', icon: $paused ? 'mdi:pause-circle' : 'mdi:numeric');
    }

    private function countChange(): void
    {
        if ($this->counting?->getValue() === false) {
            return;
        }

        $this->changeCount += (int) ($this->step?->getValue() ?? 1);

        try {
            $this->changesSeen?->setValue($this->changeCount);
        } catch (ExposureException $e) {
            $this->stopExposing($e);
        }
    }

    private function stopExposing(ExposureException $e): void
    {
        $this->changesSeen = null;
        $this->counting = null;
        $this->step = null;
        $this->logger->info('The hello entities are not exposed', ['reason' => $e->getMessage()]);
    }
}
