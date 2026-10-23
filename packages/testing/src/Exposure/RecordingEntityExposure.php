<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use LogicException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ExposedAction;
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
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Contracts\Exposure\ExposedTime;
use Stewart\Contracts\Exposure\ExposureConfig;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Testing\Exposure\Collection\RecordedExposureCollection;
use Stewart\Testing\Time\ManualTimers;

final class RecordingEntityExposure implements EntityExposure
{
    public private(set) RecordedExposureCollection $exposures;

    /** @var array<string, RecordingExposure<covariant ExposureConfig>> */
    private array $handlesByKey = [];

    /** @var array<string, ExposedEntitySnapshot> */
    private array $seededByKey = [];

    private ?ExposureException $refusal = null;

    public function __construct(
        public private(set) AppId $appId = new AppId('test-app'),
        private readonly ManualTimers $timers = new ManualTimers(),
    ) {
        $this->exposures = RecordedExposureCollection::empty();
    }

    /** @internal */
    public function assignAppId(AppId $appId): void
    {
        $this->appId = $appId;
    }

    // Stands in for what Home Assistant restored or assigned, applied when the app exposes the key.
    public function seedSnapshot(ExposedEntityKey|string $key, ExposedEntitySnapshot $snapshot): void
    {
        $this->seededByKey[ExposedEntityKey::fromKeyOrString($key)->value] = $snapshot;
    }

    public function cancelSubscriptions(): void
    {
        foreach ($this->handlesByKey as $handle) {
            $handle->cancelCommandSubscriptions();
        }
    }

    public function refuseExposures(ExposureException $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function exposeSensor(ExposedEntityKey|string $key, SensorConfig $config = new SensorConfig(), ?DeviceInfo $device = null): ExposedSensor
    {
        return $this->trackHandle(new RecordingExposedSensor($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeBinarySensor(
        ExposedEntityKey|string $key,
        BinarySensorConfig $config = new BinarySensorConfig(),
        ?DeviceInfo $device = null,
    ): ExposedBinarySensor {
        return $this->trackHandle(new RecordingExposedBinarySensor($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeSwitch(ExposedEntityKey|string $key, SwitchConfig $config = new SwitchConfig(), ?DeviceInfo $device = null): ExposedSwitch
    {
        return $this->trackHandle(new RecordingExposedSwitch($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeButton(ExposedEntityKey|string $key, ButtonConfig $config = new ButtonConfig(), ?DeviceInfo $device = null): ExposedButton
    {
        return $this->trackHandle(new RecordingExposedButton($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeNumber(ExposedEntityKey|string $key, NumberConfig $config, ?DeviceInfo $device = null): ExposedNumber
    {
        return $this->trackHandle(new RecordingExposedNumber($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeSelect(ExposedEntityKey|string $key, SelectConfig $config, ?DeviceInfo $device = null): ExposedSelect
    {
        return $this->trackHandle(new RecordingExposedSelect($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeText(ExposedEntityKey|string $key, TextConfig $config = new TextConfig(), ?DeviceInfo $device = null): ExposedText
    {
        return $this->trackHandle(new RecordingExposedText($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeTime(ExposedEntityKey|string $key, TimeConfig $config = new TimeConfig(), ?DeviceInfo $device = null): ExposedTime
    {
        return $this->trackHandle(new RecordingExposedTime($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeDate(ExposedEntityKey|string $key, DateConfig $config = new DateConfig(), ?DeviceInfo $device = null): ExposedDate
    {
        return $this->trackHandle(new RecordingExposedDate($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeDateTime(ExposedEntityKey|string $key, DateTimeConfig $config = new DateTimeConfig(), ?DeviceInfo $device = null): ExposedDateTime
    {
        return $this->trackHandle(new RecordingExposedDateTime($this->claimKey($key, $config, $device), $config, $this->timers));
    }

    public function exposeAction(ExposedEntityKey|string $key, ActionConfig $config = new ActionConfig()): ExposedAction
    {
        return $this->trackHandle(new RecordingExposedAction($this->claimKey($key, $config, null), $config, $this->timers));
    }

    public function requireSensor(ExposedEntityKey|string $key): RecordingExposedSensor
    {
        return $this->requireHandle($key, RecordingExposedSensor::class, 'sensor');
    }

    public function requireBinarySensor(ExposedEntityKey|string $key): RecordingExposedBinarySensor
    {
        return $this->requireHandle($key, RecordingExposedBinarySensor::class, 'binary sensor');
    }

    public function requireSwitch(ExposedEntityKey|string $key): RecordingExposedSwitch
    {
        return $this->requireHandle($key, RecordingExposedSwitch::class, 'switch');
    }

    public function requireButton(ExposedEntityKey|string $key): RecordingExposedButton
    {
        return $this->requireHandle($key, RecordingExposedButton::class, 'button');
    }

    public function requireNumber(ExposedEntityKey|string $key): RecordingExposedNumber
    {
        return $this->requireHandle($key, RecordingExposedNumber::class, 'number');
    }

    public function requireSelect(ExposedEntityKey|string $key): RecordingExposedSelect
    {
        return $this->requireHandle($key, RecordingExposedSelect::class, 'select');
    }

    public function requireText(ExposedEntityKey|string $key): RecordingExposedText
    {
        return $this->requireHandle($key, RecordingExposedText::class, 'text');
    }

    public function requireTime(ExposedEntityKey|string $key): RecordingExposedTime
    {
        return $this->requireHandle($key, RecordingExposedTime::class, 'time');
    }

    public function requireDate(ExposedEntityKey|string $key): RecordingExposedDate
    {
        return $this->requireHandle($key, RecordingExposedDate::class, 'date');
    }

    public function requireDateTime(ExposedEntityKey|string $key): RecordingExposedDateTime
    {
        return $this->requireHandle($key, RecordingExposedDateTime::class, 'datetime');
    }

    public function requireAction(ExposedEntityKey|string $key): RecordingExposedAction
    {
        return $this->requireHandle($key, RecordingExposedAction::class, 'action');
    }

    /** @throws ExposureException */
    private function claimKey(ExposedEntityKey|string $key, ExposureConfig $config, ?DeviceInfo $device): ExposedEntityKey
    {
        $key = ExposedEntityKey::fromKeyOrString($key);

        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        if ($this->findHandle($key)?->removed === false) {
            throw ExposureException::keyTaken($this->appId, $key);
        }

        $this->exposures = $this->exposures->withRecordedExposure(new RecordedExposure($key, $config, $device));

        return $key;
    }

    /**
     * @template THandle of RecordingExposure<covariant ExposureConfig>
     *
     * @param THandle $handle
     * @return THandle
     */
    private function trackHandle(RecordingExposure $handle): RecordingExposure
    {
        $seeded = $this->seededByKey[$handle->getKey()->value] ?? null;

        if ($seeded !== null && $handle instanceof RecordingExposedEntity) {
            $handle->applySnapshot($seeded);
        }

        return $this->handlesByKey[$handle->getKey()->value] = $handle;
    }

    /**
     * @template THandle of RecordingExposure<covariant ExposureConfig>
     *
     * @param class-string<THandle> $class
     * @return THandle
     */
    private function requireHandle(ExposedEntityKey|string $key, string $class, string $kind): RecordingExposure
    {
        $handle = $this->findHandle($key);

        return $handle instanceof $class ? $handle : throw new LogicException(\sprintf('No %s was exposed as "%s".', $kind, $key));
    }

    /** @return RecordingExposure<covariant ExposureConfig>|null */
    private function findHandle(ExposedEntityKey|string $key): ?RecordingExposure
    {
        return $this->handlesByKey[(string) $key] ?? null;
    }
}
