<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedButton;
use Stewart\Contracts\Exposure\ExposedDate;
use Stewart\Contracts\Exposure\ExposedDateTime;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Contracts\Exposure\ExposedTime;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\Exposure\ExposedCommandStreams;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;
use Stewart\Runtime\Worker\Exposure\ExposureRequester;
use Stewart\Runtime\Worker\Exposure\WorkerExposedBinarySensor;
use Stewart\Runtime\Worker\Exposure\WorkerExposedButton;
use Stewart\Runtime\Worker\Exposure\WorkerExposedDate;
use Stewart\Runtime\Worker\Exposure\WorkerExposedDateTime;
use Stewart\Runtime\Worker\Exposure\WorkerExposedEntity;
use Stewart\Runtime\Worker\Exposure\WorkerExposedNumber;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSelect;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSensor;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSwitch;
use Stewart\Runtime\Worker\Exposure\WorkerExposedText;
use Stewart\Runtime\Worker\Exposure\WorkerExposedTime;

final readonly class WorkerEntityExposure implements EntityExposure
{
    public function __construct(
        private ExposureRequester $requester,
        private ExposedHandleRegistry $handles,
        private ExposedCommandStreams $commandStreams,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId): self
    {
        return new self($this->requester, $this->handles, $this->commandStreams, ResourceScope::forApp($appId));
    }

    public function exposeSensor(ExposedEntityKey|string $key, SensorConfig $config = new SensorConfig(), ?DeviceInfo $device = null): ExposedSensor
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedSensor($this->requester, $this->handles, $this->resourceScope, $key, $config), $config, $device);
    }

    public function exposeBinarySensor(
        ExposedEntityKey|string $key,
        BinarySensorConfig $config = new BinarySensorConfig(),
        ?DeviceInfo $device = null,
    ): ExposedBinarySensor {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedBinarySensor($this->requester, $this->handles, $this->resourceScope, $key), $config, $device);
    }

    public function exposeSwitch(ExposedEntityKey|string $key, SwitchConfig $config = new SwitchConfig(), ?DeviceInfo $device = null): ExposedSwitch
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedSwitch($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeButton(ExposedEntityKey|string $key, ButtonConfig $config = new ButtonConfig(), ?DeviceInfo $device = null): ExposedButton
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedButton($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeNumber(ExposedEntityKey|string $key, NumberConfig $config, ?DeviceInfo $device = null): ExposedNumber
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedNumber($this->requester, $this->handles, $this->resourceScope, $key, $config, $this->commandStreams), $config, $device);
    }

    public function exposeSelect(ExposedEntityKey|string $key, SelectConfig $config, ?DeviceInfo $device = null): ExposedSelect
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedSelect($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeText(ExposedEntityKey|string $key, TextConfig $config = new TextConfig(), ?DeviceInfo $device = null): ExposedText
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedText($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeTime(ExposedEntityKey|string $key, TimeConfig $config = new TimeConfig(), ?DeviceInfo $device = null): ExposedTime
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedTime($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeDate(ExposedEntityKey|string $key, DateConfig $config = new DateConfig(), ?DeviceInfo $device = null): ExposedDate
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedDate($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    public function exposeDateTime(ExposedEntityKey|string $key, DateTimeConfig $config = new DateTimeConfig(), ?DeviceInfo $device = null): ExposedDateTime
    {
        $key = $this->claimKey($key);

        return $this->exposeHandle(new WorkerExposedDateTime($this->requester, $this->handles, $this->resourceScope, $key, $this->commandStreams), $config, $device);
    }

    /** @throws ExposureException */
    private function claimKey(ExposedEntityKey|string $key): ExposedEntityKey
    {
        $key = ExposedEntityKey::fromKeyOrString($key);
        $appId = $this->resourceScope->appId ?? throw ExposureException::outsideApp($key);

        if ($this->handles->isKeyTaken($this->resourceScope, $key)) {
            throw ExposureException::keyTaken($appId, $key);
        }

        return $key;
    }

    /**
     * @template THandle of WorkerExposedEntity
     *
     * @param THandle $handle
     * @return THandle
     * @throws ExposureException
     */
    private function exposeHandle(WorkerExposedEntity $handle, ExposedEntityConfig $config, ?DeviceInfo $device): WorkerExposedEntity
    {
        // Recorded before the broker answers, so a second exposure of the same key fails while the first is in flight.
        $this->handles->recordHandle($this->resourceScope, $handle->getKey(), $handle);

        try {
            $snapshot = $this->requester->requestExposure($this->resourceScope, $handle->getKey(), $config, $device, new ExposedStateChange());
        } catch (ExposureException $e) {
            $this->handles->forgetHandle($this->resourceScope, $handle->getKey());

            throw $e;
        }

        if ($snapshot !== null) {
            $handle->applySnapshot($snapshot);
        }

        return $handle;
    }
}
