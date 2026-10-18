<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use BackedEnum;
use JsonException;
use LogicException;
use stdClass;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Support\Exception\JsonShapeException;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;

final class WireMapper
{
    public function __construct(public readonly ClassShapeReader $shapes) {}

    /** @throws JsonException|StewartException */
    public function encodeObject(object $dto): string
    {
        $converter = $this->shapes->findConverterForClass($dto::class);

        if ($converter !== null) {
            return JsonEncoder::encodeToJson($converter->encodeValue($dto));
        }

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

    /** @throws StewartException */
    public function normalizeObject(object $dto): mixed
    {
        $converter = $this->shapes->findConverterForClass($dto::class);

        return $converter === null ? $this->normalizeFields($this->shapes->resolveClassShape($dto::class), $dto) : $converter->encodeValue($dto);
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
        $converter = $this->shapes->findConverterForClass($class);

        if ($converter === null) {
            return $this->decodeFields($class, $data, $path);
        }

        $decoded = $converter->decodeValue($data, $path);
        \assert($decoded instanceof $class);

        return $decoded;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<array-key, mixed> $data
     * @return T
     * @throws StewartException
     */
    private function decodeFields(string $class, array $data, string $path): object
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

        try {
            return new $class(...$arguments);
        } catch (StewartException $e) {
            throw JsonShapeException::createForReason(JsonShapeError::ObjectRefused, $e, key: $path);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     * @throws StewartException
     */
    public function decodeObjectList(string $class, mixed $data, string $path): array
    {
        $items = $this->decodeValue($this->shapes->describeListShape($class, $class), $data, $path);
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
            ValueKind::Variant => $this->normalizeVariant($shape, $value),
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

    /**
     * @return array<string, mixed>
     * @throws StewartException
     */
    private function normalizeVariant(ValueShape $shape, mixed $value): array
    {
        \assert(\is_object($value) && $shape->variants !== null);
        $name = $shape->variants->findVariantName($value) ?? throw new LogicException(\sprintf('%s is not a listed variant of %s.', $value::class, $shape->type));

        $fields = $this->normalizeNestedObject($value);

        return [$shape->variants->key => $name, ...($fields instanceof stdClass ? [] : $fields)];
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
            ValueKind::Number => \is_float($value) || \is_int($value) ? $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Bool => \is_bool($value) ? $value : throw $this->createWrongTypeException($shape, $path, $value),
            ValueKind::Json => $value,
            ValueKind::Enum => $this->decodeEnum($shape, $value, $path),
            ValueKind::Dto => $this->decodeNestedObject($shape, $value, $path),
            ValueKind::Variant => $this->decodeVariant($shape, $value, $path),
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

        return \is_array($value) ? $this->decodeFields($class, $value, $path) : throw $this->createWrongTypeException($shape, $path, $value);
    }

    /** @throws StewartException */
    private function decodeVariant(ValueShape $shape, mixed $value, string $path): object
    {
        \assert($shape->variants !== null);

        if (!\is_array($value)) {
            throw $this->createWrongTypeException($shape, $path, $value);
        }

        $key = $shape->variants->key;
        $name = $value[$key] ?? null;
        $class = \is_string($name) ? $shape->variants->variants[$name] ?? null : null;

        if ($class === null) {
            throw JsonShapeException::unexpectedValue($path . '.' . $key, 'one of ' . implode(', ', array_keys($shape->variants->variants)), $name);
        }

        return $this->decodeFields($class, $value, $path);
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

        if (!(\is_int($value) || \is_string($value)) || get_debug_type($value) !== $shape->enumBackingType) {
            throw JsonShapeException::wrongType($path, self::describeEnumCases($enum), get_debug_type($value));
        }

        return $enum::tryFrom($value) ?? throw JsonShapeException::unexpectedValue($path, self::describeEnumCases($enum), $value);
    }

    /** @param class-string<BackedEnum> $enum */
    private static function describeEnumCases(string $enum): string
    {
        return 'one of ' . implode(', ', array_map(static fn(BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    private function createWrongTypeException(ValueShape $shape, string $path, mixed $value): JsonShapeException
    {
        return JsonShapeException::wrongType($path, $shape->describeExpectedValue(), get_debug_type($value));
    }
}
