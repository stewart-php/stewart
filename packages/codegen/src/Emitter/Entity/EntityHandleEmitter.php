<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Entity;

use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Emitter\Service\ServiceMethodEmitter;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Codegen\Service\ServiceModel;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\StateChangeStream;

final readonly class EntityHandleEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['id', 'getState', 'requireState', 'watchStateChanges', 'listAttributes', 'getEntity', 'toServiceTarget', 'ha'];

    public function __construct(private ServiceMethodEmitter $services) {}

    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasEntities();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $declared = $domain->getHandleClass();
        $stateException = $context->importClass($namespace, StateException::class, $declared);

        foreach ([Entity::class, EntityId::class, HaContext::class, StateChangeStream::class, ServiceTarget::class, ServiceTargetSource::class] as $import) {
            $context->importClass($namespace, $import, $declared);
        }

        if ($domain->handleServices->containsWhere(static fn(ServiceModel $service): bool => $service->definition->returnsResponse)) {
            $context->importClass($namespace, ServiceResponse::class, $declared);
        }

        $class = $namespace->addClass($declared)
            ->setFinal()
            ->setReadOnly()
            ->addImplement(ServiceTargetSource::class)
            ->addComment(\sprintf('One `%s` entity.', $domain->domain));

        $class->addProperty('entity')->setType(Entity::class)->setPrivate();

        $constructor = $class->addMethod('__construct');
        $constructor->addParameter('ha')->setType(HaContext::class);
        $constructor->addPromotedParameter('id')->setType(EntityId::class)->setPublic();
        $constructor->setBody('$this->entity = $ha->getEntity($id);');

        $class->addMethod('getState')
            ->setReturnType('?' . $context->resolveClassName($domain->getStateClass()))
            ->setBody(\sprintf('return %s::fromNullableState($this->entity->getState());', $domain->getStateClass()));

        $class->addMethod('requireState')
            ->setReturnType($context->resolveClassName($domain->getStateClass()))
            ->addComment('@throws ' . $stateException)
            ->setBody(\sprintf('return new %s($this->entity->requireState());', $domain->getStateClass()));

        $class->addMethod('watchStateChanges')
            ->setReturnType(StateChangeStream::class)
            ->setBody('return $this->entity->watchStateChanges();');

        $class->addMethod('listAttributes')
            ->setReturnType('array')
            ->addComment('@return array<string, mixed>')
            ->setBody("\$state = \$this->entity->getState();\n\nreturn \$state === null ? [] : \$state->attributes;");

        $class->addMethod('getEntity')
            ->setReturnType(Entity::class)
            ->setBody('return $this->entity;');

        $class->addMethod('toServiceTarget')
            ->setReturnType(ServiceTarget::class)
            ->setBody('return $this->entity->toServiceTarget();');

        foreach ($domain->handleServices as $service) {
            $this->services->addHandleMethod($class, $service);
        }

        return $context->printFile($namespace, $declared);
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::EntityHandle ? self::RESERVED_MEMBER_NAMES : [];
    }
}
