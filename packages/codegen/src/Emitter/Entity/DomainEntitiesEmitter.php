<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Entity;

use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\HaContext;

final readonly class DomainEntitiesEmitter implements DomainFileEmitter
{
    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasEntities();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $declared = $domain->getEntitiesClass();
        $context->importClass($namespace, HaContext::class, $declared);
        $entityId = $context->importClass($namespace, EntityId::class, $declared);
        $identifierException = $context->importClass($namespace, IdentifierException::class, $declared);

        $class = $namespace->addClass($declared)
            ->setFinal()
            ->setReadOnly()
            ->addComment(\sprintf('The `%s` entities.', $domain->domain));

        $class->addMethod('__construct')
            ->addPromotedParameter('ha')
            ->setType(HaContext::class)
            ->setPrivate();

        $class->addMethod('getEntity')
            ->setReturnType($context->resolveClassName($domain->getHandleClass()))
            ->addComment('@throws ' . $identifierException)
            ->setBody(\sprintf('return new %s($this->ha, %s::fromStringOrId($id));', $domain->getHandleClass(), $entityId))
            ->addParameter('id')
            ->setType('string|' . EntityId::class);

        return $context->printFile($namespace, $declared);
    }
}
