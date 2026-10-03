<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;

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

        return $context->printStatements(self::FILE_NAME, self::META_NAMESPACE, implode("\n", $statements));
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
