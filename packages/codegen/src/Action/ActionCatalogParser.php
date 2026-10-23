<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action;

use Stewart\Codegen\Action\Collection\ActionDefinitionCollection;
use Stewart\Codegen\Snapshot\RawMap;

final readonly class ActionCatalogParser
{
    /** @param array<string, mixed> $raw */
    public function parseServicesResponse(array $raw): ActionCatalog
    {
        $byDomain = [];

        $response = RawMap::fromValue($raw);

        foreach ($response->listKeys() as $domain) {
            $actions = $response->readMap($domain);
            $definitions = [];

            foreach ($actions->listKeys() as $name) {
                $definitions[$name] = $this->parseDefinition($domain, $name, $actions->readMap($name));
            }

            ksort($definitions);
            $byDomain[$domain] = ActionDefinitionCollection::fromList($definitions);
        }

        ksort($byDomain);

        return new ActionCatalog($byDomain);
    }

    private function parseDefinition(string $domain, string $name, RawMap $action): ActionDefinition
    {
        return new ActionDefinition(
            domain: $domain,
            name: $name,
            title: $action->readString('name'),
            description: $action->readString('description'),
            fields: $this->sortRequiredFirstThenByName($this->flattenFields($action->readMap('fields'))),
            target: ActionTargetSpec::fromRaw($action),
            returnsResponse: $action->hasKey('response'),
        );
    }

    /** @return list<ActionField> */
    private function flattenFields(RawMap $fields): array
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

        return $flat;
    }

    private function parseField(string $name, RawMap $field): ActionField
    {
        $selector = new ActionSelector($field->readMap('selector'));
        $configuration = $selector->configuration;

        return new ActionField(
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

    /**
     * @param list<ActionField> $fields
     * @return list<ActionField>
     */
    private function sortRequiredFirstThenByName(array $fields): array
    {
        usort($fields, static fn(ActionField $a, ActionField $b): int => [!$a->required, $a->name] <=> [!$b->required, $b->name]);

        return $fields;
    }
}
