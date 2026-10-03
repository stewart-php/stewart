<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Closure;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\JsonShapeException;
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
