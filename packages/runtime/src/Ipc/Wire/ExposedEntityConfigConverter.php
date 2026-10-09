<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use BackedEnum;
use LogicException;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\BinarySensorDeviceClass;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\ButtonDeviceClass;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\EntityCategory;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\NumberDeviceClass;
use Stewart\Contracts\Exposure\NumberMode;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SensorStateClass;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\SwitchDeviceClass;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TextMode;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Runtime\Json\ValueConverter;

final readonly class ExposedEntityConfigConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return ExposedEntityConfig::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof ExposedEntityConfig);

        $platformFields = match (true) {
            $value instanceof SensorConfig => [
                'device_class' => $value->deviceClass?->value,
                'unit' => $value->unit,
                'state_class' => $value->stateClass?->value,
                'display_precision' => $value->displayPrecision,
                'options' => $value->options,
            ],
            $value instanceof BinarySensorConfig, $value instanceof SwitchConfig, $value instanceof ButtonConfig => ['device_class' => $value->deviceClass?->value],
            $value instanceof NumberConfig => [
                'min' => $value->min,
                'max' => $value->max,
                'step' => $value->step,
                'mode' => $value->mode->value,
                'device_class' => $value->deviceClass?->value,
                'unit' => $value->unit,
            ],
            $value instanceof SelectConfig => ['options' => $value->options],
            $value instanceof TextConfig => ['min' => $value->min, 'max' => $value->max, 'pattern' => $value->pattern, 'mode' => $value->mode->value],
            $value instanceof TimeConfig, $value instanceof DateConfig, $value instanceof DateTimeConfig => [],
            default => throw new LogicException(\sprintf('%s has no IPC encoding.', $value::class)),
        };

        return [
            'platform' => $value->getPlatform()->value,
            'name' => $value->name,
            'icon' => $value->icon,
            'entity_category' => $value->entityCategory?->value,
            'enabled_by_default' => $value->enabledByDefault,
            ...$platformFields,
        ];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): ExposedEntityConfig
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $platform = $this->readEnum($value, 'platform', ExposedPlatform::class, $path) ?? throw JsonShapeException::missing($path . '.platform', 'a platform');

        try {
            return match ($platform) {
                ExposedPlatform::Sensor => new SensorConfig(
                    deviceClass: $this->readEnum($value, 'device_class', SensorDeviceClass::class, $path),
                    unit: $this->readOptionalString($value, 'unit', $path),
                    stateClass: $this->readEnum($value, 'state_class', SensorStateClass::class, $path),
                    displayPrecision: $this->readOptionalInt($value, 'display_precision', $path),
                    options: $this->readStringList($value, 'options', $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::BinarySensor => new BinarySensorConfig(
                    deviceClass: $this->readEnum($value, 'device_class', BinarySensorDeviceClass::class, $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Switch => new SwitchConfig(
                    deviceClass: $this->readEnum($value, 'device_class', SwitchDeviceClass::class, $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Button => new ButtonConfig(
                    deviceClass: $this->readEnum($value, 'device_class', ButtonDeviceClass::class, $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Number => new NumberConfig(
                    min: $this->readNumber($value, 'min', $path),
                    max: $this->readNumber($value, 'max', $path),
                    step: $this->readNumber($value, 'step', $path),
                    mode: $this->readEnum($value, 'mode', NumberMode::class, $path) ?? throw JsonShapeException::missing($path . '.mode', 'a number mode'),
                    deviceClass: $this->readEnum($value, 'device_class', NumberDeviceClass::class, $path),
                    unit: $this->readOptionalString($value, 'unit', $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Select => new SelectConfig(
                    options: $this->readStringList($value, 'options', $path),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Text => new TextConfig(
                    min: $this->readInt($value, 'min', $path),
                    max: $this->readInt($value, 'max', $path),
                    pattern: $this->readOptionalString($value, 'pattern', $path),
                    mode: $this->readEnum($value, 'mode', TextMode::class, $path) ?? throw JsonShapeException::missing($path . '.mode', 'a text mode'),
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Time => new TimeConfig(
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::Date => new DateConfig(
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
                ExposedPlatform::DateTime => new DateTimeConfig(
                    name: $this->readOptionalString($value, 'name', $path),
                    icon: $this->readOptionalString($value, 'icon', $path),
                    entityCategory: $this->readEnum($value, 'entity_category', EntityCategory::class, $path),
                    enabledByDefault: $this->readBool($value, 'enabled_by_default', $path),
                ),
            };
        } catch (ExposureException $e) {
            throw JsonShapeException::unexpectedValue($path, 'a valid entity configuration', $e->getMessage());
        }
    }

    /**
     * @template T of BackedEnum
     *
     * @param array<array-key, mixed> $fields
     * @param class-string<T> $enum
     * @return T|null
     * @throws JsonShapeException
     */
    private function readEnum(array $fields, string $key, string $enum, string $path): ?BackedEnum
    {
        $raw = $this->readOptionalString($fields, $key, $path);

        return $raw === null ? null : ($enum::tryFrom($raw) ?? throw JsonShapeException::unexpectedValue($path . '.' . $key, 'a known ' . $key, $raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readOptionalString(array $fields, string $key, string $path): ?string
    {
        $raw = $fields[$key] ?? null;

        return $raw === null || \is_string($raw) ? $raw : throw JsonShapeException::wrongType($path . '.' . $key, 'a string', get_debug_type($raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readOptionalInt(array $fields, string $key, string $path): ?int
    {
        $raw = $fields[$key] ?? null;

        return $raw === null || \is_int($raw) ? $raw : throw JsonShapeException::wrongType($path . '.' . $key, 'an integer', get_debug_type($raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readInt(array $fields, string $key, string $path): int
    {
        return $this->readOptionalInt($fields, $key, $path) ?? throw JsonShapeException::missing($path . '.' . $key, 'an integer');
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readNumber(array $fields, string $key, string $path): int|float
    {
        $raw = $fields[$key] ?? null;

        return \is_int($raw) || \is_float($raw) ? $raw : throw JsonShapeException::wrongType($path . '.' . $key, 'a number', get_debug_type($raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readBool(array $fields, string $key, string $path): bool
    {
        $raw = $fields[$key] ?? null;

        return \is_bool($raw) ? $raw : throw JsonShapeException::wrongType($path . '.' . $key, 'a boolean', get_debug_type($raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return list<string>
     * @throws JsonShapeException
     */
    private function readStringList(array $fields, string $key, string $path): array
    {
        $raw = $fields[$key] ?? [];

        if (!\is_array($raw) || !array_is_list($raw) || array_filter($raw, is_string(...)) !== $raw) {
            throw JsonShapeException::wrongType($path . '.' . $key, 'a list of strings', get_debug_type($raw));
        }

        /** @var list<string> $raw */
        return $raw;
    }
}
