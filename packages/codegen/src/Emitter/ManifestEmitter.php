<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Contracts\Generated\GeneratedFormat;
use Stewart\Contracts\Generated\Manifest;

final readonly class ManifestEmitter implements ModelFileEmitter
{
    public const string CLASS_NAME = 'Manifest';

    public function emitFile(GenerationModel $model, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, Manifest::class, self::CLASS_NAME);

        $class = $namespace->addClass(self::CLASS_NAME)
            ->setFinal()
            ->addComment('What `stewart generate` saw.');

        $class->addImplement(Manifest::class);
        $class->addConstant(GeneratedFormat::MANIFEST_CONSTANT, GeneratedFormat::VERSION)->setType('int')->setPublic();
        $class->addMethod('__construct')->setPrivate();

        $class->addMethod('listEntityIds')
            ->setStatic()
            ->setReturnType('array')
            ->addComment('@return list<string>')
            ->setBody('return ?;', [$model->entityIds->toStrings()]);

        $class->addMethod('listIgnoredEntityIds')
            ->setStatic()
            ->setReturnType('array')
            ->addComment('@return list<string>')
            ->setBody('return ?;', [$model->ignoredEntityIds->toStrings()]);

        return $context->printFile($namespace, self::CLASS_NAME);
    }
}
