<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Stewart\Runtime\Config\BooleanSpelling;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Support\Text\ClosestNameFinder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

final readonly class AppOptionResolver
{
    private const array COERCIBLE_TYPES = ['bool', 'int', 'float'];

    public function __construct(private ClosestNameFinder $closestNameFinder) {}

    /**
     * @return array<string, mixed>
     * @throws ContainerException
     */
    public function resolveOptionArguments(WorkerApp $app): array
    {
        if (!class_exists($app->class)) {
            throw ContainerException::appClassMissing($app->id, $app->class);
        }

        $parameters = $this->listConstructorParameters($app->class);
        $arguments = [];

        foreach ($app->options as $name => $value) {
            $parameter = $parameters[$name] ?? throw ContainerException::appOptionUnknown(
                $app->id,
                $name,
                $app->class,
                $this->closestNameFinder->findClosestName($name, array_keys($parameters)),
            );

            $arguments['$' . $name] = $this->escapeParameterPlaceholders($this->coerceToParameterType($app, $parameter, $value));
        }

        foreach ($parameters as $name => $parameter) {
            if (!\array_key_exists($name, $app->options) && $this->needsExplicitValue($parameter)) {
                throw ContainerException::appOptionMissing($app->id, $name, $app->class);
            }
        }

        return $arguments;
    }

    /**
     * @param class-string $class
     * @return array<string, ReflectionParameter>
     */
    private function listConstructorParameters(string $class): array
    {
        $parameters = [];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    // Mirrors Symfony's AutowirePass: a parameter with no service type and no default needs a configured value.
    private function needsExplicitValue(ReflectionParameter $parameter): bool
    {
        return !$parameter->isVariadic()
            && !$parameter->isDefaultValueAvailable()
            && !$parameter->isOptional()
            && !$this->hasAutowiringAttribute($parameter)
            && !$this->namesAService($parameter->getType());
    }

    private function hasAutowiringAttribute(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(Autowire::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || $parameter->getAttributes(Lazy::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || $parameter->getAttributes(AutowireDecorated::class) !== [];
    }

    private function namesAService(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return !$type->isBuiltin();
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return array_any($type->getTypes(), $this->namesAService(...));
        }

        return false;
    }

    /** @throws ContainerException */
    private function coerceToParameterType(WorkerApp $app, ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if (!\is_string($value) || !$type instanceof ReflectionNamedType || !\in_array($type->getName(), self::COERCIBLE_TYPES, true)) {
            return $value;
        }

        $coerced = match ($type->getName()) {
            'bool' => BooleanSpelling::tryParseBoolean($value),
            'int' => filter_var(trim($value), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE),
            default => is_numeric(trim($value)) ? (float) trim($value) : null,
        };

        return $coerced ?? throw ContainerException::appOptionInvalid($app->id->value, $parameter->getName(), $value, $type->getName());
    }

    // Symfony resolves %name% in arguments; app options are literals.
    private function escapeParameterPlaceholders(mixed $value): mixed
    {
        if (\is_string($value)) {
            return str_replace('%', '%%', $value);
        }

        if (\is_array($value)) {
            return array_map($this->escapeParameterPlaceholders(...), $value);
        }

        return $value;
    }
}
