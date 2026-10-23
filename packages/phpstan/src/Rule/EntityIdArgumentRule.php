<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Registry\Registry;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\PHPStan\Manifest\GeneratedHandles;
use Stewart\PHPStan\Manifest\ManifestCatalog;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;
use Stewart\Support\Text\ClosestNameFinder;

/** @implements Rule<CallLike> */
final readonly class EntityIdArgumentRule implements Rule
{
    private const string IDENTIFIER = 'stewart.entityId';

    private const string GENERATED_GETTER = 'getEntity';

    /** @var list<EntityIdParameter> */
    private array $parameters;

    public function __construct(
        private ManifestCatalogLoader $manifests,
        private EntityIdLiterals $literals,
        private TestCodeScope $testCode,
        private GeneratedHandles $handles,
        private ClosestNameFinder $names,
    ) {
        $this->parameters = [
            new EntityIdParameter(HaContext::class, 'getState', 'entityId'),
            new EntityIdParameter(HaContext::class, 'requireState', 'entityId'),
            new EntityIdParameter(HaContext::class, 'getEntity', 'entityId'),
            new EntityIdParameter(HaContext::class, 'getHistory', 'entityId'),
            new EntityIdParameter(HaContext::class, 'waitForState', 'entityId'),
            new EntityIdParameter(HaContext::class, 'listStates', 'selector'),
            new EntityIdParameter(HaContext::class, 'watchStateChanges', 'selector'),
            new EntityIdParameter(ActionTarget::class, 'forEntities', 'entityIds', variadic: true),
            new EntityIdParameter(Scheduler::class, 'runAtEntityTime', 'entity'),
            new EntityIdParameter(HaTrigger::class, 'atTime', 'time'),
            new EntityIdParameter(HaTrigger::class, 'onZoneTransition', 'entity'),
            new EntityIdParameter(HaTrigger::class, 'onZoneTransition', 'zone', position: 1),
            new EntityIdParameter(Registry::class, 'findEntity', 'entityId'),
            new EntityIdParameter(Registry::class, 'findEntityPlacement', 'entityId'),
            new EntityIdParameter(RegistryEditor::class, 'updateEntity', 'entityId'),
            new EntityIdParameter(EntityId::class, '__construct', 'value'),
            new EntityIdParameter(EntityId::class, 'fromStringOrId', 'id'),
            new EntityIdParameter(EntityId::class, 'tryFromString', 'value'),
        ];
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param Scope&DependencyTracker $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $called = CalledMethod::findForCall($node, $scope);

        if ($called === null || $this->testCode->isInTestCode($scope)) {
            return [];
        }

        $parameters = array_values(array_filter($this->parameters, static fn(EntityIdParameter $parameter): bool => $called->isCallTo($parameter->class, $parameter->method)));
        $handleClasses = $this->listGeneratedHandleClasses($node, $called, $scope);

        if ($parameters === [] && $handleClasses === []) {
            return [];
        }

        $catalog = $this->manifests->findCatalogTrackedBy($scope);

        if ($catalog === null) {
            return [];
        }

        $errors = [];

        foreach ($parameters as $parameter) {
            foreach ($called->listArgumentValues($parameter->position, $parameter->name, $parameter->variadic) as $value) {
                foreach ($this->literals->listEntityIds($scope->getType($value)) as $entityId) {
                    if (!$catalog->knowsEntity($entityId)) {
                        $errors[] = $this->buildUnknownEntityError($entityId, $catalog);
                    }
                }
            }
        }

        foreach ($handleClasses as $handleClass) {
            $errors = [...$errors, ...$this->checkGeneratedGetter($handleClass, $called, $scope, $catalog)];
        }

        return $errors;
    }

    /** @return list<string> */
    private function listGeneratedHandleClasses(CallLike $node, CalledMethod $called, Scope $scope): array
    {
        $handle = $scope->getType($node);

        if (strcasecmp($called->method, self::GENERATED_GETTER) !== 0 || !new ObjectType(TypedEntity::class)->isSuperTypeOf($handle)->yes()) {
            return [];
        }

        return array_values(array_filter($handle->getObjectClassNames(), $this->handles->isHandle(...)));
    }

    // A generated <Domain>Entities::getEntity() throws EntityNotGenerated for anything its domain did not generate.
    /** @return list<IdentifierRuleError> */
    private function checkGeneratedGetter(string $handleClass, CalledMethod $called, Scope $scope, ManifestCatalog $catalog): array
    {
        $errors = [];

        foreach ($called->listArgumentValues(0, 'id') as $value) {
            foreach ($this->literals->listEntityIds($scope->getType($value)) as $entityId) {
                if ($this->handles->isGeneratedEntityId($handleClass, $entityId)) {
                    continue;
                }

                $errors[] = match (true) {
                    $catalog->isIgnoredEntity($entityId) => RuleErrorBuilder::message(\sprintf('Entity ID "%s" is left out of the generated classes, so getEntity() throws EntityNotGenerated.', $entityId))
                        ->identifier(self::IDENTIFIER)
                        ->tip('Use HaContext::getEntity() for it, or include it in codegen.')
                        ->build(),
                    $catalog->knowsEntity($entityId) => RuleErrorBuilder::message(\sprintf('Entity ID "%s" is not a %s entity, so this getEntity() throws EntityNotGenerated.', $entityId, $this->handles->findDomain($handleClass)))
                        ->identifier(self::IDENTIFIER)
                        ->build(),
                    default => $this->buildUnknownEntityError($entityId, $catalog),
                };
            }
        }

        return $errors;
    }

    private function buildUnknownEntityError(string $entityId, ManifestCatalog $catalog): IdentifierRuleError
    {
        $suggestion = $this->names->findClosestName($entityId, $catalog->listKnownEntityIds());

        return RuleErrorBuilder::message(\sprintf(
            'Entity ID "%s" was not in Home Assistant at the last stewart generate.%s',
            $entityId,
            $suggestion === null ? '' : \sprintf(' Did you mean "%s"?', $suggestion),
        ))
            ->identifier(self::IDENTIFIER)
            ->tip('Run make generate if the entity is new.')
            ->build();
    }
}
