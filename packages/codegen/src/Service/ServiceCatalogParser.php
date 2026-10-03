<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Service\Collection\ServiceDefinitionCollection;
use Stewart\Codegen\Service\Collection\ServiceFieldCollection;
use Stewart\Codegen\Snapshot\RawMap;

final readonly class ServiceCatalogParser
{
    /** @param array<string, mixed> $raw */
    public function parseServicesResponse(array $raw): ServiceCatalog
    {
        $byDomain = [];

        $response = RawMap::fromValue($raw);

        foreach ($response->listKeys() as $domain) {
            $services = $response->readMap($domain);
            $definitions = [];

            foreach ($services->listKeys() as $name) {
                $definitions[$name] = $this->parseDefinition($domain, $name, $services->readMap($name));
            }

            ksort($definitions);
            $byDomain[$domain] = ServiceDefinitionCollection::fromDefinitions($definitions);
        }

        ksort($byDomain);

        return new ServiceCatalog($byDomain);
    }

    private function parseDefinition(string $domain, string $name, RawMap $service): ServiceDefinition
    {
        return new ServiceDefinition(
            domain: $domain,
            name: $name,
            title: $service->readString('name'),
            description: $service->readString('description'),
            fields: $this->sortRequiredFirstThenByName($this->flattenFields($service->readMap('fields'))),
            target: ServiceTargetSpec::fromRaw($service),
            returnsResponse: $service->hasKey('response'),
        );
    }

    private function flattenFields(RawMap $fields): ServiceFieldCollection
    {
        $flat = [];

        foreach ($fields->listKeys() as $name) {
            $field = $fields->readMap($name);

            if ($field->hasKey('fields')) {
                $flat = [...$flat, ...$this->flattenFields($field->readMap('fields'))];

                continue;
            }

            $flat[] = $this->parseField($name, $field);
        }

        return ServiceFieldCollection::fromFields($flat);
    }

    private function parseField(string $name, RawMap $field): ServiceField
    {
        $selector = new ServiceSelector($field->readMap('selector'));
        $configuration = $selector->configuration;

        return new ServiceField(
            name: $name,
            type: $selector->resolvePhpType(),
            required: $field->readBool('required'),
            label: $field->readString('name'),
            description: $field->readString('description'),
            unit: $configuration->readString('unit_of_measurement'),
            min: $configuration->readNumber('min'),
            max: $configuration->readNumber('max'),
        );
    }

    private function sortRequiredFirstThenByName(ServiceFieldCollection $fields): ServiceFieldCollection
    {
        $byName = static fn(ServiceField $a, ServiceField $b): int => strcmp($a->name, $b->name);
        $required = $fields->filter(static fn(ServiceField $field): bool => $field->required)->sortedBy($byName);
        $optional = $fields->filter(static fn(ServiceField $field): bool => !$field->required)->sortedBy($byName);

        return ServiceFieldCollection::fromFields([...$required, ...$optional]);
    }
}
