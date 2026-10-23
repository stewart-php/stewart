<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class GeneratedNamespaceResolver
{
    // What a new project's composer.json maps; generate suggests it when nothing maps the directory yet.
    private const string NAMESPACE_WITHOUT_MAPPING = 'Automations\\Generated';

    public function __construct(
        private Psr4Map $psr4Map,
        private ProjectRoot $projectRoot,
    ) {}

    /** @throws ConfigurationException */
    public function resolveNamespaceFor(ProjectDirectory $outputDir): ClassNamespace
    {
        $prefixes = $this->psr4Map->listPrefixesMappedTo($outputDir->resolveWithin($this->projectRoot->path));

        if (\count($prefixes) > 1) {
            throw ConfigurationException::createForReason(
                ConfigurationError::GeneratedNamespaceAmbiguous,
                namespaces: implode(', ', array_map(static fn(string $prefix): string => rtrim($prefix, '\\'), $prefixes)),
                directory: $outputDir->value,
            );
        }

        return new ClassNamespace(isset($prefixes[0]) ? rtrim($prefixes[0], '\\') : self::NAMESPACE_WITHOUT_MAPPING);
    }
}
