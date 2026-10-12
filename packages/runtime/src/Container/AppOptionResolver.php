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
use Stewart\Runtime\Exception\ContainerError;
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
            throw ContainerException::createForReason(ContainerError::AppClassMissing, appId: $app->id->value, class: $app->class);
        }

        $parameters = $this->listConstructorParameters($app->class);
        $arguments = [];
        $optionsByParameter = [];

        foreach ($app->options as $option => $value) {
            $option = (string) $option;
            $parameter = $this->findParameterForOption($app, $parameters, $option) ?? throw ContainerException::appOptionUnknown(
                $app->id,
                $option,
                $app->class,
                $this->closestNameFinder->findClosestName($option, array_keys($parameters)),
            );
            $name = $parameter->getName();

            if (isset($optionsByParameter[$name])) {
                throw ContainerException::createForReason(
                    ContainerError::AppOptionRepeated,
                    appId: $app->id->value,
                    parameter: $name,
                    class: $app->class,
                    firstOption: $optionsByParameter[$name],
                    secondOption: $option,
                );
            }

            $optionsByParameter[$name] = $option;
            $arguments['$' . $name] = $this->escapeParameterPlaceholders($this->coerceToParameterType($app, $parameter, $value));
        }

        foreach ($parameters as $name => $parameter) {
            if (!isset($optionsByParameter[$name]) && $this->needsExplicitValue($parameter)) {
                throw ContainerException::createForReason(ContainerError::AppOptionMissing, appId: $app->id->value, option: $name, class: $app->class);
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, ReflectionParameter> $parameters
     * @throws ContainerException
     */
    private function findParameterForOption(WorkerApp $app, array $parameters, string $option): ?ReflectionParameter
    {
        if (isset($parameters[$option])) {
            return $parameters[$option];
        }

        $folded = self::foldOptionName($option);
        $matches = array_values(array_filter($parameters, static fn(ReflectionParameter $parameter): bool => self::foldOptionName($parameter->getName()) === $folded));

        if (\count($matches) > 1) {
            throw ContainerException::appOptionAmbiguous($app->id, $option, $app->class, array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $matches));
        }

        return $matches[0] ?? null;
    }

    // Environment variable names lose case and word breaks, so MOTION_SENSOR has to reach $motionSensor.
    private static function foldOptionName(string $name): string
    {
        return strtolower(str_replace(['_', '-'], '', $name));
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

        return $coerced ?? throw ContainerException::createForReason(
            ContainerError::AppOptionInvalid,
            appId: $app->id->value,
            option: $parameter->getName(),
            value: $value,
            expectedType: $type->getName(),
        );
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
