<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Json\Collection\FieldShapeCollection;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;

final class ClassShapeReader
{
    private const array ANY_JSON = ['array', 'bool', 'float', 'int', 'null', 'string'];

    private const array SCALARS = [
        'string' => ValueKind::String,
        'int' => ValueKind::Int,
        'float' => ValueKind::Float,
        'bool' => ValueKind::Bool,
    ];

    /** @var array<class-string, ClassShape> */
    private array $classShapes = [];

    /** @var array<class-string, true> */
    private array $classesBeingDescribed = [];

    public function __construct(private readonly ValueConverterCollection $converters) {}

    /** @param class-string $class */
    public function resolveClassShape(string $class): ClassShape
    {
        return $this->classShapes[$class] ?? $this->buildClassShape($class);
    }

    /** @param class-string $class */
    private function buildClassShape(string $class): ClassShape
    {
        if (isset($this->classesBeingDescribed[$class])) {
            throw new LogicException(\sprintf('%s refers to itself, which the wire cannot carry.', $class));
        }

        $this->classesBeingDescribed[$class] = true;

        try {
            $constructor = new ReflectionClass($class)->getConstructor();

            if ($constructor !== null && !$constructor->isPublic()) {
                throw new LogicException(\sprintf('%s needs a public constructor to be built from the wire.', $class));
            }

            $fields = array_map(
                fn(ReflectionParameter $parameter): FieldShape => $this->buildFieldShape($class, $parameter),
                $constructor?->getParameters() ?? [],
            );

            return $this->classShapes[$class] = new ClassShape($class, FieldShapeCollection::fromFieldShapes($fields));
        } finally {
            unset($this->classesBeingDescribed[$class]);
        }
    }

    /** @param class-string $class */
    private function buildFieldShape(string $class, ReflectionParameter $parameter): FieldShape
    {
        $name = $parameter->getName();
        $where = $class . '::$' . $name;

        if (!$parameter->isPromoted() || !new ReflectionProperty($class, $name)->isPublic()) {
            throw new LogicException(\sprintf('%s must be a public promoted constructor parameter.', $where));
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            $names = array_map(static fn(ReflectionNamedType|ReflectionIntersectionType $part): string => (string) $part, $type->getTypes());
            sort($names);

            if ($names !== self::ANY_JSON) {
                throw new LogicException(\sprintf('%s has a union type the wire cannot carry.', $where));
            }

            return new FieldShape($name, $this->convertToWireKey($name), true, new ValueShape(ValueKind::Json));
        }

        if (!$type instanceof ReflectionNamedType) {
            throw new LogicException(\sprintf('%s needs a declared type.', $where));
        }

        $value = match ($type->getName()) {
            'mixed' => new ValueShape(ValueKind::Json),
            'array' => $this->buildArrayShape($parameter, $where),
            default => $this->describeValueShape($type->getName(), $where),
        };

        return new FieldShape($name, $this->convertToWireKey($name) . ($value->converter?->keySuffix() ?? ''), $type->allowsNull(), $value);
    }

    private function buildArrayShape(ReflectionParameter $parameter, string $where): ValueShape
    {
        $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;

        return $listOf === null
            ? new ValueShape(ValueKind::Map)
            : new ValueShape(ValueKind::List, element: $this->describeValueShape($listOf->newInstance()->type, $where));
    }

    public function describeValueShape(string $type, string $where): ValueShape
    {
        if (isset(self::SCALARS[$type])) {
            return new ValueShape(self::SCALARS[$type], $type);
        }

        $converter = $this->converters->findForClass($type);

        if ($converter !== null) {
            return new ValueShape(ValueKind::Converted, $type, converter: $converter);
        }

        if (is_subclass_of($type, JsonFragment::class)) {
            return new ValueShape(ValueKind::Fragment, $type);
        }

        if (is_subclass_of($type, BackedEnum::class)) {
            return new ValueShape(ValueKind::Enum, $type, enumBackingType: (string) new ReflectionEnum($type)->getBackingType());
        }

        if (!class_exists($type) || new ReflectionClass($type)->isAbstract()) {
            throw new LogicException(\sprintf('%s is a %s, which has no converter.', $where, $type));
        }

        if ($this->resolveClassShape($type)->holdsFragment) {
            throw new LogicException(\sprintf('%s nests %s, and only a top-level object may hold a fragment.', $where, $type));
        }

        return new ValueShape(ValueKind::Dto, $type);
    }

    private function convertToWireKey(string $propertyName): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
    }
}
