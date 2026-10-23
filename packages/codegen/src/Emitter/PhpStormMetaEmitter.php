<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\Registry;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Trigger\HaTrigger;

final readonly class PhpStormMetaEmitter implements ModelFileEmitter
{
    public const string FILE_NAME = '.phpstorm.meta.php';

    private const string META_NAMESPACE = 'PHPSTORM_META';

    private const string ZONE_DOMAIN = 'zone';

    // forEntities() is variadic, and PhpStorm completes each argument position on its own.
    private const int COMPLETED_VARIADIC_ARGUMENTS = 6;

    private const array ENTITY_ID_METHODS = [
        HaContext::class . '::getState',
        HaContext::class . '::requireState',
        HaContext::class . '::getHeldStateDuration',
        HaContext::class . '::hasHeldStateFor',
        HaContext::class . '::getEntity',
        HaContext::class . '::getHistory',
        HaContext::class . '::waitForState',
        HaContext::class . '::listStates',
        HaContext::class . '::watchStateChanges',
        Scheduler::class . '::runAtEntityTime',
        HaTrigger::class . '::atTime',
        HaTrigger::class . '::onZoneTransition',
        Registry::class . '::findEntity',
        Registry::class . '::findEntityPlacement',
        RegistryEditor::class . '::updateEntity',
        EntityId::class . '::__construct',
        EntityId::class . '::fromStringOrId',
        EntityId::class . '::tryFromString',
    ];

    private const array ACTION_NAME_METHODS = [
        HaContext::class . '::callAction',
        HaContext::class . '::callActionForResponse',
    ];

    public function emitFile(GenerationModel $model, EmitContext $context): GeneratedFile
    {
        $statements = [];

        foreach ($model->listEntityDomains() as $domain) {
            $set = $this->buildDomainSetName($domain);
            $statements[] = $this->buildSetRegistration($set, $domain->entityIds->toStrings());
            $statements[] = $this->buildExpectation($context->resolveClassName($domain->getEntitiesClass()) . '::getEntity', $set);

            if ($domain->domain === self::ZONE_DOMAIN) {
                $statements[] = $this->buildExpectation(HaTrigger::class . '::onZoneTransition', $set, 1);
            }
        }

        $statements = [
            ...$statements,
            ...$this->buildEntityIdCompletion($model),
            ...$this->buildIdCompletion('stewart_action_names', $model->listActionNames(), self::ACTION_NAME_METHODS),
            ...$this->buildIdCompletion('stewart_area_ids', $model->areaIds->toStrings(), [
                EntityFilter::class . '::inArea',
                EntityFilter::class . '::withArea',
                Registry::class . '::findArea',
                Registry::class . '::listDevicesInArea',
                ActionTarget::class . '::forAreas',
                AreaId::class . '::__construct',
            ]),
            ...$this->buildIdCompletion('stewart_floor_ids', $model->floorIds->toStrings(), [
                EntityFilter::class . '::onFloor',
                EntityFilter::class . '::withFloor',
                Registry::class . '::findFloor',
                Registry::class . '::listAreasOnFloor',
                ActionTarget::class . '::forFloors',
                FloorId::class . '::__construct',
            ]),
            ...$this->buildIdCompletion('stewart_label_ids', $model->labelIds->toStrings(), [
                EntityFilter::class . '::labelled',
                EntityFilter::class . '::withLabel',
                Registry::class . '::findLabel',
                ActionTarget::class . '::forLabels',
                LabelId::class . '::__construct',
            ]),
        ];

        return $context->printStatements(self::FILE_NAME, self::META_NAMESPACE, implode("\n", $statements));
    }

    /** @return list<string> */
    private function buildEntityIdCompletion(GenerationModel $model): array
    {
        $entityIds = [...$model->entityIds->toStrings(), ...$model->ignoredEntityIds->toStrings()];
        sort($entityIds);
        $statements = $this->buildIdCompletion('stewart_entity_ids', $entityIds, self::ENTITY_ID_METHODS);

        if ($statements === []) {
            return [];
        }

        for ($argument = 0; $argument < self::COMPLETED_VARIADIC_ARGUMENTS; ++$argument) {
            $statements[] = $this->buildExpectation(ActionTarget::class . '::forEntities', 'stewart_entity_ids', $argument);
        }

        return $statements;
    }

    /**
     * @param list<string> $ids
     * @param list<string> $methods
     * @return list<string>
     */
    private function buildIdCompletion(string $set, array $ids, array $methods): array
    {
        if ($ids === []) {
            return [];
        }

        return [
            $this->buildSetRegistration($set, $ids),
            ...array_map(fn(string $method): string => $this->buildExpectation($method, $set), $methods),
        ];
    }

    private function buildDomainSetName(DomainModel $domain): string
    {
        return \sprintf('stewart_%s_entity_ids', $domain->domain);
    }

    /** @param list<string> $values */
    private function buildSetRegistration(string $set, array $values): string
    {
        $arguments = array_map(static fn(string $value): string => var_export($value, true), [$set, ...$values]);

        return \sprintf('registerArgumentsSet(%s);', implode(', ', $arguments));
    }

    private function buildExpectation(string $method, string $set, int $argument = 0): string
    {
        return \sprintf("expectedArguments(\\%s(), %d, argumentsSet('%s'));", $method, $argument, $set);
    }
}
