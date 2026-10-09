<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use LogicException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\SensorConfig;
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
