<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Attribute\AttributeKind;
use Stewart\Codegen\Attribute\AttributeKindInference;
use Stewart\Codegen\Attribute\AttributeModel;
use Stewart\Codegen\Attribute\AttributeSelector;
use Stewart\Codegen\Attribute\Collection\AttributeModelCollection;
use Stewart\Codegen\Entity\EntityModelFactory;
use Stewart\Codegen\Entity\EntitySelector;
use Stewart\Codegen\Entity\UnmatchedEntitySelector;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\Collection\GenerationWarningCollection;
use Stewart\Codegen\Php\Identifier;
use Stewart\Codegen\Php\MemberReservations;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Service\Collection\FieldModelCollection;
use Stewart\Codegen\Service\Collection\ServiceDefinitionCollection;
use Stewart\Codegen\Service\Collection\ServiceModelCollection;
use Stewart\Codegen\Service\FieldModel;
use Stewart\Codegen\Service\ServiceCatalog;
use Stewart\Codegen\Service\ServiceCatalogParser;
use Stewart\Codegen\Service\ServiceDefinition;
use Stewart\Codegen\Service\ServiceModel;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;

final readonly class GenerationModelFactory
{
    public function __construct(
        private AttributeKindInference $attributeKinds,
        private EntitySelector $entitySelector,
        private EntityModelFactory $entityModels,
        private AttributeSelector $attributeSelector,
        private ServiceCatalogParser $serviceCatalogParser,
        private MemberReservations $memberReservations,
        private DomainCatalog $domainCatalog,
    ) {}

    /** @throws CodegenException */
    public function buildModelFromSnapshot(Snapshot $snapshot, GenerationOptions $options): GenerationModel
    {
        $selection = $this->entitySelector->selectEntities($snapshot, $options->filter);
        $catalog = $this->serviceCatalogParser->parseServicesResponse($snapshot->services);
        $byDomain = $selection->generated->groupByDomain();

        $accessors = $this->memberReservations->createAllocatorFor(MemberScope::RootClasses);
        $classStems = $this->memberReservations->createAllocatorFor(MemberScope::ClassStems);
        $domains = [];
        $unseenAttributes = [];

        foreach ($this->sortedDomainNames($selection->generated, $catalog) as $domain) {
            $states = $byDomain[$domain] ?? EntityStateCollection::empty();
            $attributes = $this->attributeSelector->selectAttributes($domain, $this->attributeKinds->inferKindsByAttributeName($states), $options->attributesFor($domain));
            $unseenAttributes = [...$unseenAttributes, ...$attributes->unseen];

            $domains[] = new DomainModel(
                domain: $domain,
                accessor: $accessors->claim(Identifier::convertToCamelCase($domain)),
                classStem: $classStems->claim(Identifier::convertToPascalCase($domain)),
                traits: $this->domainCatalog->getTraitsForDomain($domain),
                entities: $this->entityModels->buildEntityModels($states, $options->renames),
                attributes: $this->buildAttributeModels($attributes->typed),
                handleServices: $this->buildServiceModels($catalog->forEntityHandle($domain), MemberScope::EntityHandle),
                services: $this->buildServiceModels($catalog->forDomain($domain), MemberScope::DomainServices),
            );
        }

        return new GenerationModel(
            domains: DomainModelCollection::fromDomains($domains),
            entityIds: $selection->listEntityIds(),
            ignoredEntityIds: $selection->ignored,
            warnings: GenerationWarningCollection::fromWarnings([
                ...$options->filter->listUnmatchedIncludes($snapshot->states)->mapToList(
                    static fn(Selector $include): UnmatchedEntitySelector => new UnmatchedEntitySelector($include->getPattern()),
                ),
                ...$this->entityModels->listUnmatchedRenames($selection->generated, $options->renames),
                ...$unseenAttributes,
            ]),
        );
    }

    /** @return list<string> */
    private function sortedDomainNames(EntityStateCollection $states, ServiceCatalog $catalog): array
    {
        $domains = array_values(array_unique([...$states->listDomains(), ...$catalog->listDomains()]));
        sort($domains);

        return $domains;
    }

    /** @param array<string, AttributeKind> $typed */
    private function buildAttributeModels(array $typed): AttributeModelCollection
    {
        $names = $this->memberReservations->createAllocatorFor(MemberScope::StateView);
        $attributes = [];

        foreach ($typed as $key => $kind) {
            $key = (string) $key;
            $attributes[] = new AttributeModel($names->claim('get' . ucfirst(Identifier::convertToCamelCase($key))), $key, $kind);
        }

        return AttributeModelCollection::fromAttributes($attributes);
    }

    private function buildServiceModels(ServiceDefinitionCollection $definitions, MemberScope $methodScope): ServiceModelCollection
    {
        $names = $this->memberReservations->createAllocatorFor($methodScope);
        $services = [];

        foreach ($definitions as $definition) {
            $services[] = new ServiceModel(
                $names->claim(Identifier::convertToCamelCase($definition->name)),
                $definition,
                $this->buildFieldModels($definition, $this->selectParameterScope($methodScope, $definition)),
            );
        }

        return ServiceModelCollection::fromServices($services);
    }

    private function selectParameterScope(MemberScope $methodScope, ServiceDefinition $definition): MemberScope
    {
        return $methodScope === MemberScope::DomainServices && $definition->target->isTargetable
            ? MemberScope::TargetedParameters
            : MemberScope::Parameters;
    }

    private function buildFieldModels(ServiceDefinition $definition, MemberScope $parameterScope): FieldModelCollection
    {
        $names = $this->memberReservations->createAllocatorFor($parameterScope);
        $models = [];

        foreach ($definition->fields as $field) {
            $models[] = new FieldModel($names->claim(Identifier::convertToCamelCase($field->name)), $field);
        }

        return FieldModelCollection::fromFields($models);
    }
}
