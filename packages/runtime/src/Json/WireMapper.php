<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use BackedEnum;
use JsonException;
use stdClass;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;

final class WireMapper
{
    public function __construct(public readonly ClassShapeReader $shapes) {}

    /** @throws JsonException|StewartException */
    public function encodeObject(object $dto): string
    {
        $shape = $this->shapes->resolveClassShape($dto::class);

        if (!$shape->holdsFragment) {
            return JsonEncoder::encodeToJson($this->normalizeFields($shape, $dto));
        }

        $members = [];

        foreach ($shape->fields as $field) {
            $value = $dto->{$field->property};
            $members[] = JsonEncoder::encodeToJson($field->key) . ':' . ($value instanceof JsonFragment
                ? $value->encodeToJson($this)
                : JsonEncoder::encodeToJson($this->normalizeValue($field->value, $value)));
        }

        return '{' . implode(',', $members) . '}';
    }

    /**
     * @param list<object> $dtos
     * @throws JsonException|StewartException
     */
    public function encodeObjectList(array $dtos): string
    {
        return JsonEncoder::encodeToJson(array_map($this->normalizeObject(...), $dtos));
    }

    /**
     * @return array<string, mixed>|stdClass
     * @throws StewartException
     */
    public function normalizeObject(object $dto): array|stdClass
    {
        return $this->normalizeFields($this->shapes->resolveClassShape($dto::class), $dto);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<array-key, mixed> $data
     * @return T
     * @throws StewartException
     */
    public function decodeObject(string $class, array $data, string $path = ''): object
    {
        $arguments = [];

        foreach ($this->shapes->resolveClassShape($class)->fields as $field) {
            $fieldPath = $path === '' ? $field->key : $path . '.' . $field->key;

            if (!\array_key_exists($field->key, $data)) {
                throw JsonShapeException::missing($fieldPath, $field->value->describeExpectedValue());
            }

            $value = $data[$field->key];
            $arguments[] = $value === null && $field->nullable ? null : $this->decodeValue($field->value, $value, $fieldPath);
        }

        return new $class(...$arguments);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     * @throws StewartException
     */
    public function decodeObjectList(string $class, mixed $data, string $path): array
    {
        $element = $this->shapes->describeValueShape($class, $class);
        $items = $this->decodeValue(new ValueShape(ValueKind::List, element: $element), $data, $path);
        \assert(\is_array($items) && array_is_list($items));

        /** @var list<T> $items */
        return $items;
    }

    /**
     * @return array<string, mixed>|stdClass
     * @throws StewartException
     */
    private function normalizeFields(ClassShape $shape, object $dto): array|stdClass
    {
        $normalized = [];

        foreach ($shape->fields as $field) {
            $value = $dto->{$field->property};
            $normalized[$field->key] = $value === null || $field->value->passesThrough ? $value : $this->normalizeValue($field->value, $value);
        }

        return $normalized === [] ? new stdClass() : $normalized;
    }

    /** @throws StewartException */
    private function normalizeValue(ValueShape $shape, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($shape->kind) {
            ValueKind::Enum => $this->normalizeEnum($value),
            ValueKind::Dto => $this->normalizeNestedObject($value),
            ValueKind::Converted => $this->normalizeConvertedValue($shape, $value),
            ValueKind::List => $this->normalizeListItems($shape, $value),
            ValueKind::Map => $this->normalizeMap($value),
            default => $value,
        };
    }

    private function normalizeEnum(mixed $value): int|string
    {
        \assert($value instanceof BackedEnum);

        return $value->value;
    }

    /**
     * @return array<string, mixed>|stdClass
     * @throws StewartException
     */
    private function normalizeNestedObject(mixed $value): array|stdClass
    {
        \assert(\is_object($value));

        return $this->normalizeFields($this->shapes->resolveClassShape($value::class), $value);
    }

    private function normalizeConvertedValue(ValueShape $shape, mixed $value): mixed
    {
        \assert(\is_object($value) && $shape->converter !== null);

        return $shape->converter->encodeValue($value);
    }

    /**
     * @return array<array-key, mixed>
     * @throws StewartException
     */
    private function normalizeListItems(ValueShape $shape, mixed $value): array
    {
        \assert(\is_array($value) && $shape->element !== null);
        $element = $shape->element;

        return $element->passesThrough ? $value : array_map(fn(mixed $item): mixed => $this->normalizeValue($element, $item), $value);
    }

    /** @return array<array-key, mixed>|object */
    private function normalizeMap(mixed $value): array|object
    {
        \assert(\is_array($value));

        // Cast so a list-shaped map encodes as a JSON object, not an array.
        return array_is_list($value) ? (object) $value : $value;
    }

    /** @throws StewartException */
    private function decodeValue(ValueShape $shape, mixed $value, string $path): mixed
    {
        return match ($shape->kind) {
            ValueKind::String => \is_string($value) ? $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Int => \is_int($value) ? $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Float => \is_float($value) || \is_int($value) ? (float) $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Bool => \is_bool($value) ? $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Json => $value,
            ValueKind::Enum => $this->decodeEnum($shape, $value, $path),
            ValueKind::Dto => $this->decodeNestedObject($shape, $value, $path),
            ValueKind::Converted => $this->decodeConvertedValue($shape, $value, $path),
            ValueKind::Map => \is_array($value) ? JsonShape::treatKeysAsStrings($value) : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Fragment => $this->decodeFragment($shape, $value, $path),
            ValueKind::List => $this->decodeListItems($shape, $value, $path),
        };
    }

    /** @throws StewartException */
    private function decodeNestedObject(ValueShape $shape, mixed $value, string $path): object
    {
        /** @var class-string $class */
        $class = $shape->type;

        return \is_array($value) ? $this->decodeObject($class, $value, $path) : throw $this->createWrongTypeException($shape, $path, $value);
    }

    /** @throws StewartException */
    private function decodeConvertedValue(ValueShape $shape, mixed $value, string $path): mixed
    {
        \assert($shape->converter !== null);

        return $shape->converter->decodeValue($value, $path);
    }

    /** @throws StewartException */
    private function decodeFragment(ValueShape $shape, mixed $value, string $path): JsonFragment
    {
        /** @var class-string<JsonFragment> $fragment */
        $fragment = $shape->type;

        return $fragment::fromDecodedValue($value, $path, $this);
    }

    /**
     * @return list<mixed>
     * @throws StewartException
     */
    private function decodeListItems(ValueShape $shape, mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->createWrongTypeException($shape, $path, $value);
        }

        \assert($shape->element !== null);
        $items = [];

        foreach ($value as $index => $item) {
            $items[] = $this->decodeValue($shape->element, $item, $path . '.' . $index);
        }

        return $items;
    }

    /** @throws JsonShapeException */
    private function decodeEnum(ValueShape $shape, mixed $value, string $path): BackedEnum
    {
        $enum = $shape->type;
        \assert(is_subclass_of($enum, BackedEnum::class));
        $expected = 'one of ' . implode(', ', array_map(static fn(BackedEnum $case): string => (string) $case->value, $enum::cases()));

        if (!(\is_int($value) || \is_string($value)) || get_debug_type($value) !== $shape->enumBackingType) {
            throw JsonShapeException::wrongType($path, $expected, get_debug_type($value));
        }

        return $enum::tryFrom($value) ?? throw JsonShapeException::unexpectedValue($path, $expected, $value);
    }

    private function createWrongTypeException(ValueShape $shape, string $path, mixed $value): JsonShapeException
    {
        return JsonShapeException::wrongType($path, $shape->describeExpectedValue(), get_debug_type($value));
    }
}
