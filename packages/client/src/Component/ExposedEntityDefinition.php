<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use LogicException;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SwitchConfig;

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
