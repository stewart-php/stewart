<?php

declare(strict_types=1);

namespace Stewart\Testing\App;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\Store;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\Stores;
use Stewart\Support\Text\OptionNameMatcher;
use Stewart\Testing\Exception\HarnessError;
use Stewart\Testing\Exception\HarnessException;

/** @internal */
final readonly class AppArgumentResolver
{
    /** @param array<string, object> $servicesByType */
    public function __construct(
        private array $servicesByType,
        private Stores $stores,
        private HaContext $ha,
        private OptionNameMatcher $optionNames,
    ) {}

    /**
     * @template T of App
     * @param ReflectionClass<T> $app
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws HarnessException
     */
    public function resolveArguments(ReflectionClass $app, array $options): array
    {
        $parameters = $app->getConstructor()?->getParameters() ?? [];
        $optionsByParameter = $this->matchOptionsToParameters($app, $options, array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $parameters));
        $arguments = [];

        foreach ($parameters as $parameter) {
            $arguments[$parameter->getName()] = \array_key_exists($parameter->getName(), $optionsByParameter)
                ? $options[$optionsByParameter[$parameter->getName()]]
                : $this->resolveParameter($app, $parameter);
        }

        return $arguments;
    }

    /**
     * @template T of App
     * @param ReflectionClass<T> $app
     * @param array<string, mixed> $options
     * @param list<string> $parameters
     * @return array<string, string>
     * @throws HarnessException
     */
    private function matchOptionsToParameters(ReflectionClass $app, array $options, array $parameters): array
    {
        $optionsByParameter = [];

        foreach (array_keys($options) as $option) {
            $match = $this->optionNames->matchOption($option, $parameters);

            if ($match->isAmbiguous()) {
                throw HarnessException::createForReason(
                    HarnessError::OptionAmbiguous,
                    option: $option,
                    class: $app->getName(),
                    parameters: implode(', ', array_map(static fn(string $name): string => '$' . $name, $match->candidates)),
                );
            }

            $parameter = $match->parameter ?? throw HarnessException::optionUnknown($option, $app->getName(), $match->suggestion);

            if (isset($optionsByParameter[$parameter])) {
                throw HarnessException::createForReason(
                    HarnessError::OptionRepeated,
                    class: $app->getName(),
                    parameter: $parameter,
                    firstOption: $optionsByParameter[$parameter],
                    secondOption: $option,
                );
            }

            $optionsByParameter[$parameter] = $option;
        }

        return $optionsByParameter;
    }

    /**
     * @template T of App
     * @param ReflectionClass<T> $app
     * @throws HarnessException
     */
    private function resolveParameter(ReflectionClass $app, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $store = $this->findStoreFor($parameter, $typeName);

        if ($store !== null) {
            return $store;
        }

        if ($typeName !== null && isset($this->servicesByType[$typeName])) {
            return $this->servicesByType[$typeName];
        }

        if ($typeName !== null && self::isBuiltOnHaContext($typeName)) {
            return new $typeName($this->ha);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type?->allowsNull() === true) {
            return null;
        }

        if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
            throw HarnessException::createForReason(HarnessError::OptionMissing, class: $app->getName(), option: $parameter->getName());
        }

        throw HarnessException::createForReason(HarnessError::ParameterUnresolvable, class: $app->getName(), parameter: $parameter->getName(), type: (string) $type);
    }

    private function findStoreFor(ReflectionParameter $parameter, ?string $typeName): ?object
    {
        if ($parameter->getAttributes(GlobalStore::class) !== []) {
            $global = $this->stores->openGlobalStore();

            return $typeName === Store::class ? $global : new ReadOnlyStore($global);
        }

        $peer = ($parameter->getAttributes(PeerStore::class)[0] ?? null)?->newInstance();

        return $peer === null ? null : new ReadOnlyStore($this->stores->openAppStore(new AppId($peer->appId)));
    }

    // The generated Entities and Services roots take the app's HaContext and nothing else.
    private static function isBuiltOnHaContext(string $typeName): bool
    {
        if (!class_exists($typeName)) {
            return false;
        }

        $required = array_values(array_filter(
            new ReflectionClass($typeName)->getConstructor()?->getParameters() ?? [],
            static fn(ReflectionParameter $parameter): bool => !$parameter->isOptional(),
        ));
        $type = ($required[0] ?? null)?->getType();

        return \count($required) === 1 && $type instanceof ReflectionNamedType && $type->getName() === HaContext::class;
    }
}
