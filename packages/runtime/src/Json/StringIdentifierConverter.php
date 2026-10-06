<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Closure;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;
use Stringable;

/** @template T of Stringable */
final readonly class StringIdentifierConverter implements ValueConverter
{
    /**
     * @param class-string<T> $identifierClass
     * @param Closure(string): ?T $parse
     */
    public function __construct(
        private string $identifierClass,
        private string $expected,
        private Closure $parse,
    ) {}

    /** @return self<AppId> */
    public static function createForAppIds(): self
    {
        return new self(AppId::class, 'an app id', AppId::tryFromString(...));
    }

    /** @return self<EntityId> */
    public static function createForEntityIds(): self
    {
        return new self(EntityId::class, 'an entity id', EntityId::tryFromString(...));
    }

    /** @return self<AreaId> */
    public static function createForAreaIds(): self
    {
        return new self(AreaId::class, 'an area id', AreaId::tryFromString(...));
    }

    /** @return self<FloorId> */
    public static function createForFloorIds(): self
    {
        return new self(FloorId::class, 'a floor id', FloorId::tryFromString(...));
    }

    /** @return self<LabelId> */
    public static function createForLabelIds(): self
    {
        return new self(LabelId::class, 'a label id', LabelId::tryFromString(...));
    }

    /** @return self<DeviceId> */
    public static function createForDeviceIds(): self
    {
        return new self(DeviceId::class, 'a device id', DeviceId::tryFromString(...));
    }

    public function handledClass(): string
    {
        return $this->identifierClass;
    }

    public function keySuffix(): string
    {
        return '';
    }

    public function encodeValue(object $value): string
    {
        \assert($value instanceof $this->identifierClass);

        return (string) $value;
    }

    /** @return T */
    public function decodeValue(mixed $value, string $path): object
    {
        if (!\is_string($value)) {
            throw JsonShapeException::wrongType($path, $this->expected, get_debug_type($value));
        }

        return ($this->parse)($value) ?? throw JsonShapeException::unexpectedValue($path, $this->expected, $value);
    }
}
