<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Entity;

use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;

final readonly class DomainEntitiesEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['ha'];

    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasEntities();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, HaContext::class, $domain->getEntitiesClass());
        $entityId = $context->importClass($namespace, EntityId::class, $domain->getEntitiesClass());

        $class = $namespace->addClass($domain->getEntitiesClass())
            ->setFinal()
            ->addComment(\sprintf('The `%s` entities.', $domain->domain));

        $class->addMethod('__construct')
            ->addPromotedParameter('ha')
            ->setType(HaContext::class)
            ->setPrivate()
            ->setReadOnly();

        foreach ($domain->entities as $entity) {
            $class->addProperty($entity->accessor)
                ->setType($context->resolveClassName($domain->getHandleClass()))
                ->addComment($entity->friendlyName)
                ->addHook('get', \sprintf(
                    'new %s($this->ha, new %s(%s))',
                    $domain->getHandleClass(),
                    $entityId,
                    var_export($entity->entityId->value, true),
                ));
        }

        return $context->printFile($namespace, $domain->getEntitiesClass());
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::DomainCollection ? self::RESERVED_MEMBER_NAMES : [];
    }
}
