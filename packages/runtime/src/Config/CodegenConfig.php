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
     * @param array<string, string> $rename
     */
    public function __construct(
        public ClassNamespace $namespace,
        public ProjectDirectory $outputDir,
        public array $include,
        public array $exclude,
        public array $rename,
        public DomainAttributesConfigCollection $attributes,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $codegen): self
    {
        return new self(
            namespace: $codegen->readParsedValue('namespace', static fn(string $namespace): ClassNamespace => new ClassNamespace($namespace)),
            outputDir: $codegen->readParsedValue('output_dir', static fn(string $directory): ProjectDirectory => new ProjectDirectory($directory)),
            include: $codegen->readStringList('include'),
            exclude: $codegen->readStringList('exclude'),
            rename: $codegen->readStringMap('rename'),
            attributes: DomainAttributesConfigCollection::keyedByDomain(
                $codegen->readSection('attributes')->mapSubsections(DomainAttributesConfig::fromSection(...)),
            ),
        );
    }
}
