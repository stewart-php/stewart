<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exception\ExposureException;

final readonly class SensorConfig extends ExposedEntityConfig
{
    /**
     * @param list<string> $options
     * @throws ExposureException
     */
    public function __construct(
        public ?SensorDeviceClass $deviceClass = null,
        public ?string $unit = null,
        public ?SensorStateClass $stateClass = null,
        public ?int $displayPrecision = null,
        public array $options = [],
        ?string $name = null,
        ?string $icon = null,
        ?EntityCategory $entityCategory = null,
        bool $enabledByDefault = true,
    ) {
        parent::__construct($name, $icon, $entityCategory, $enabledByDefault);

        if ($unit === '') {
            throw ExposureException::configInvalid('The sensor unit must not be empty; leave it null for none.');
        }

        if ($displayPrecision !== null && $displayPrecision < 0) {
            throw ExposureException::configInvalid('The sensor display precision must not be negative.');
        }

        if (($deviceClass === SensorDeviceClass::Enum) !== ($options !== [])) {
            throw ExposureException::configInvalid('A sensor takes options exactly when its device class is enum.');
        }

        if (\in_array('', $options, true) || \count(array_unique($options)) !== \count($options)) {
            throw ExposureException::configInvalid('The sensor options must be distinct and not empty.');
        }
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Sensor;
    }

    /** @throws ExposureException */
    public function formatState(int|float|string|DateTimeInterface|null $value): int|float|string|null
    {
        return match (true) {
            $value instanceof DateTimeInterface && $this->deviceClass?->takesDateTime() === true => CalendarStateFormat::formatDateTime($value),
            $value instanceof DateTimeInterface && $this->deviceClass?->takesDate() === true => CalendarStateFormat::formatDate($value),
            $value instanceof DateTimeInterface => throw ExposureException::stateInvalid('A date or time needs the timestamp or date device class.'),
            \is_float($value) && !is_finite($value) => throw ExposureException::stateInvalid('A sensor state must be a finite number.'),
            default => $value,
        };
    }
}
