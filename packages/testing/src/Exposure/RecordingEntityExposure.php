<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use LogicException;
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
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Contracts\Exposure\ExposedTime;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Testing\Exposure\Collection\RecordedExposureCollection;

final class RecordingEntityExposure implements EntityExposure
{
    public private(set) RecordedExposureCollection $exposures;

    /** @var array<string, RecordingExposedEntity> */
    private array $handlesByKey = [];

    /** @var array<string, ExposedEntitySnapshot> */
    private array $seededByKey = [];

    private ?ExposureException $refusal = null;

    public function __construct(public readonly AppId $appId = new AppId('test-app'))
    {
        $this->exposures = RecordedExposureCollection::empty();
    }

    // Stands in for what Home Assistant restored or assigned, applied when the app exposes the key.
    public function seedSnapshot(ExposedEntityKey|string $key, ExposedEntitySnapshot $snapshot): void
    {
        $this->seededByKey[ExposedEntityKey::fromKeyOrString($key)->value] = $snapshot;
    }

    public function refuseExposures(ExposureException $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function exposeSensor(ExposedEntityKey|string $key, SensorConfig $config = new SensorConfig(), ?DeviceInfo $device = null): ExposedSensor
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedSensor($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeBinarySensor(
        ExposedEntityKey|string $key,
        BinarySensorConfig $config = new BinarySensorConfig(),
        ?DeviceInfo $device = null,
    ): ExposedBinarySensor {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedBinarySensor($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeSwitch(ExposedEntityKey|string $key, SwitchConfig $config = new SwitchConfig(), ?DeviceInfo $device = null): ExposedSwitch
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedSwitch($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeButton(ExposedEntityKey|string $key, ButtonConfig $config = new ButtonConfig(), ?DeviceInfo $device = null): ExposedButton
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedButton($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeNumber(ExposedEntityKey|string $key, NumberConfig $config, ?DeviceInfo $device = null): ExposedNumber
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedNumber($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeSelect(ExposedEntityKey|string $key, SelectConfig $config, ?DeviceInfo $device = null): ExposedSelect
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedSelect($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeText(ExposedEntityKey|string $key, TextConfig $config = new TextConfig(), ?DeviceInfo $device = null): ExposedText
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedText($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeTime(ExposedEntityKey|string $key, TimeConfig $config = new TimeConfig(), ?DeviceInfo $device = null): ExposedTime
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedTime($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeDate(ExposedEntityKey|string $key, DateConfig $config = new DateConfig(), ?DeviceInfo $device = null): ExposedDate
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedDate($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function exposeDateTime(ExposedEntityKey|string $key, DateTimeConfig $config = new DateTimeConfig(), ?DeviceInfo $device = null): ExposedDateTime
    {
        $key = $this->claimKey($key, $config, $device);

        return $this->handlesByKey[$key->value] = new RecordingExposedDateTime($key, $config, $this->seededByKey[$key->value] ?? null);
    }

    public function requireSensor(ExposedEntityKey|string $key): RecordingExposedSensor
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedSensor ? $handle : throw new LogicException(\sprintf('No sensor was exposed as "%s".', $key));
    }

    public function requireBinarySensor(ExposedEntityKey|string $key): RecordingExposedBinarySensor
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedBinarySensor ? $handle : throw new LogicException(\sprintf('No binary sensor was exposed as "%s".', $key));
    }

    public function requireSwitch(ExposedEntityKey|string $key): RecordingExposedSwitch
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedSwitch ? $handle : throw new LogicException(\sprintf('No switch was exposed as "%s".', $key));
    }

    public function requireButton(ExposedEntityKey|string $key): RecordingExposedButton
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedButton ? $handle : throw new LogicException(\sprintf('No button was exposed as "%s".', $key));
    }

    public function requireNumber(ExposedEntityKey|string $key): RecordingExposedNumber
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedNumber ? $handle : throw new LogicException(\sprintf('No number was exposed as "%s".', $key));
    }

    public function requireSelect(ExposedEntityKey|string $key): RecordingExposedSelect
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedSelect ? $handle : throw new LogicException(\sprintf('No select was exposed as "%s".', $key));
    }

    public function requireText(ExposedEntityKey|string $key): RecordingExposedText
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedText ? $handle : throw new LogicException(\sprintf('No text was exposed as "%s".', $key));
    }

    public function requireTime(ExposedEntityKey|string $key): RecordingExposedTime
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedTime ? $handle : throw new LogicException(\sprintf('No time was exposed as "%s".', $key));
    }

    public function requireDate(ExposedEntityKey|string $key): RecordingExposedDate
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedDate ? $handle : throw new LogicException(\sprintf('No date was exposed as "%s".', $key));
    }

    public function requireDateTime(ExposedEntityKey|string $key): RecordingExposedDateTime
    {
        $handle = $this->findHandle($key);

        return $handle instanceof RecordingExposedDateTime ? $handle : throw new LogicException(\sprintf('No datetime was exposed as "%s".', $key));
    }

    /** @throws ExposureException */
    private function claimKey(ExposedEntityKey|string $key, ExposedEntityConfig $config, ?DeviceInfo $device): ExposedEntityKey
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

    private function findHandle(ExposedEntityKey|string $key): ?RecordingExposedEntity
    {
        return $this->handlesByKey[(string) $key] ?? null;
    }
}
