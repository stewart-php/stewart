<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\Registry;
use Stewart\Contracts\Service\ServiceTarget;

final readonly class PhpStormMetaEmitter implements ModelFileEmitter
{
    public const string FILE_NAME = '.phpstorm.meta.php';

    private const string META_NAMESPACE = 'PHPSTORM_META';

    public function emitFile(GenerationModel $model, EmitContext $context): GeneratedFile
    {
        $statements = [];

        foreach ($model->listEntityDomains() as $domain) {
            $set = $this->buildDomainSetName($domain);
            $statements[] = $this->buildSetRegistration($set, $domain->entityIds->toStrings());
            $statements[] = $this->buildExpectation($context->resolveClassName($domain->getEntitiesClass()) . '::getEntity', $set);
        }

        $statements = [
            ...$statements,
            ...$this->buildRegistryIdCompletion('stewart_area_ids', $model->areaIds->toStrings(), [
                EntityFilter::class . '::inArea',
                EntityFilter::class . '::withArea',
                Registry::class . '::findArea',
                Registry::class . '::listDevicesInArea',
                ServiceTarget::class . '::forAreas',
                AreaId::class . '::__construct',
            ]),
            ...$this->buildRegistryIdCompletion('stewart_floor_ids', $model->floorIds->toStrings(), [
                EntityFilter::class . '::onFloor',
                EntityFilter::class . '::withFloor',
                Registry::class . '::findFloor',
                Registry::class . '::listAreasOnFloor',
                ServiceTarget::class . '::forFloors',
                FloorId::class . '::__construct',
            ]),
            ...$this->buildRegistryIdCompletion('stewart_label_ids', $model->labelIds->toStrings(), [
                EntityFilter::class . '::labelled',
                EntityFilter::class . '::withLabel',
                Registry::class . '::findLabel',
                ServiceTarget::class . '::forLabels',
                LabelId::class . '::__construct',
            ]),
        ];

        return $context->printStatements(self::FILE_NAME, self::META_NAMESPACE, implode("\n", $statements));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $methods
     * @return list<string>
     */
    private function buildRegistryIdCompletion(string $set, array $ids, array $methods): array
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

    private function buildExpectation(string $method, string $set): string
    {
        return \sprintf("expectedArguments(\\%s(), 0, argumentsSet('%s'));", $method, $set);
    }
}
