<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use LogicException;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TimeConfig;

final readonly class ExposedEntityDefinition
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, string>|null $device
     */
    public function __construct(
        public ExposedPlatform $platform,
        public array $config,
        public ?array $device = null,
    ) {}

    public static function fromConfig(ExposedEntityConfig $config, ?DeviceInfo $device): self
    {
        $platformFields = match (true) {
            $config instanceof SensorConfig => [
                'device_class' => $config->deviceClass?->value,
                'unit_of_measurement' => $config->unit,
                'state_class' => $config->stateClass?->value,
                'suggested_display_precision' => $config->displayPrecision,
                'options' => $config->options === [] ? null : $config->options,
            ],
            $config instanceof BinarySensorConfig, $config instanceof SwitchConfig, $config instanceof ButtonConfig => ['device_class' => $config->deviceClass?->value],
            $config instanceof NumberConfig => [
                'min' => $config->min,
                'max' => $config->max,
                'step' => $config->step,
                'mode' => $config->mode->value,
                'device_class' => $config->deviceClass?->value,
                'unit_of_measurement' => $config->unit,
            ],
            $config instanceof SelectConfig => ['options' => $config->options],
            $config instanceof TextConfig => ['min' => $config->min, 'max' => $config->max, 'pattern' => $config->pattern, 'mode' => $config->mode->value],
            $config instanceof TimeConfig, $config instanceof DateConfig, $config instanceof DateTimeConfig => [],
            default => throw new LogicException(\sprintf('%s has no component encoding.', $config::class)),
        };
        $commonFields = [
            'name' => $config->name,
            'icon' => $config->icon,
            'entity_category' => $config->entityCategory?->value,
            'enabled_by_default' => $config->enabledByDefault ? null : false,
        ];

        return new self(
            $config->getPlatform(),
            self::dropNullFields([...$commonFields, ...$platformFields]),
            $device === null ? null : self::dropNullFields([
                'identifier' => $device->identifier,
                'name' => $device->name,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'suggested_area' => $device->suggestedArea,
            ]),
        );
    }

    public function withConfigOf(self $other): self
    {
        return new self($other->platform, $other->config, $this->device);
    }

    /** @return array<string, mixed> */
    public function toMessageFields(): array
    {
        return ['platform' => $this->platform->value, 'config' => $this->config, ...($this->device === null ? [] : ['device' => $this->device])];
    }

    /**
     * @template T
     *
     * @param array<string, T|null> $fields
     * @return array<string, T>
     */
    private static function dropNullFields(array $fields): array
    {
        return array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }
}
