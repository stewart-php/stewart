<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Config\Collection\DomainAttributesConfigCollection;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class CodegenConfig
{
    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public function __construct(
        public ClassNamespace $namespace,
        public ProjectDirectory $outputDir,
        public array $include,
        public array $exclude,
        public DomainAttributesConfigCollection $attributes,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $codegen, GeneratedNamespaceResolver $namespaces): self
    {
        $outputDir = $codegen->readParsedValue('output_dir', static fn(string $directory): ProjectDirectory => new ProjectDirectory($directory));

        return new self(
            namespace: $codegen->findParsedValue('namespace', static fn(string $namespace): ClassNamespace => new ClassNamespace($namespace))
                ?? $namespaces->resolveNamespaceFor($outputDir),
            outputDir: $outputDir,
            include: $codegen->readStringList('include'),
            exclude: $codegen->readStringList('exclude'),
            attributes: DomainAttributesConfigCollection::keyedByDomain(
                $codegen->readSection('attributes')->mapSubsections(DomainAttributesConfig::fromSection(...)),
            ),
        );
    }
}
