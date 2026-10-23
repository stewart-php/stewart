<?php

declare(strict_types=1);

namespace Automations;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;

#[Automation(id: 'rain-warning')]
final readonly class RainWarning implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private LoggerInterface $logger,
        private string $weather = 'weather.home',
        private int $minimumChance = 50,
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runDailyAt('07:00', $this->checkTheForecast(...));
    }

    private function checkTheForecast(): void
    {
        try {
            $answer = $this->ha->callActionForResponse('weather.get_forecasts', ['type' => 'daily'], ActionTarget::forEntities($this->weather));
        } catch (ActionCallException $e) {
            $this->logger->warning('No forecast today', ['reason' => $e->getMessage()]);

            return;
        }

        $chance = $this->readChanceOfRain($answer);

        if ($chance >= $this->minimumChance) {
            $this->ha->callAction('notify.notify', ['message' => "Take an umbrella: {$chance}% chance of rain."]);
        }
    }

    // The response is Home Assistant's JSON as arrays, so each level is checked before it is read.
    private function readChanceOfRain(ActionResponse $answer): int
    {
        $entity = $answer->response[$this->weather] ?? null;
        $forecast = \is_array($entity) ? ($entity['forecast'] ?? null) : null;
        $today = \is_array($forecast) ? ($forecast[0] ?? null) : null;
        $chance = \is_array($today) ? ($today['precipitation_probability'] ?? null) : null;

        return is_numeric($chance) ? (int) $chance : 0;
    }
}
