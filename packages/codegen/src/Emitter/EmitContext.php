<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpNamespace;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Output\GeneratedFile;

final readonly class EmitContext
{
    private const string CLASHING_IMPORT_SUFFIX = 'Contract';

    public function __construct(
        private GenerationTarget $target,
        private GeneratedCodePrinter $printer,
    ) {}

    public function createNamespace(): PhpNamespace
    {
        return new PhpNamespace($this->target->namespace);
    }

    public function importClass(PhpNamespace $namespace, string $class, string $declaredShortName): string
    {
        $shortName = Helpers::extractShortName($class);

        if (strcasecmp($shortName, $declaredShortName) !== 0) {
            $namespace->addUse($class);

            return $shortName;
        }

        $namespace->addUse($class, $shortName . self::CLASHING_IMPORT_SUFFIX);

        return $shortName . self::CLASHING_IMPORT_SUFFIX;
    }

    public function resolveClassName(string $shortName): string
    {
        return $this->target->classFor($shortName);
    }

    public function printFile(PhpNamespace $namespace, string $shortName): GeneratedFile
    {
        return GeneratedFile::forClass($shortName, $this->printer->printGenerated($namespace));
    }
}
