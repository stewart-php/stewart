<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Runtime\Model\ResourceScope;

final class WorkerExposedSensor extends WorkerExposedEntity implements ExposedSensor
{
    public function __construct(
        ExposureRequester $requester,
        ExposedHandleRegistry $handles,
        ResourceScope $scope,
        ExposedEntityKey $key,
        private readonly SensorConfig $config,
    ) {
        parent::__construct($requester, $handles, $scope, $key);
    }

    public function getValue(): int|float|string|null
    {
        $value = $this->findStateValue();

        return \is_bool($value) ? null : $value;
    }

    public function setValue(int|float|string|DateTimeInterface|null $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($this->config->formatState($value)), $attributes));
    }
}
