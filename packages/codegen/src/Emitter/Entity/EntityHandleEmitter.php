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
use Stewart\Contracts\Entity\TypedEntity;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\StateChangeStream;

final readonly class EntityHandleEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['id', 'getDomain', 'isGeneratedEntityId', 'getState', 'requireState', 'watchStateChanges', 'getHistory', 'listAttributes', 'getEntity', 'toServiceTarget', 'ha'];

    private const string ENTITY_IDS_CONSTANT = 'GENERATED_ENTITY_IDS';

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
        $identifierException = $context->importClass($namespace, IdentifierException::class, $declared);
        $historyException = $context->importClass($namespace, HistoryException::class, $declared);

        foreach ([Entity::class, EntityId::class, HaContext::class, EntityStateHistory::class, HistoryQuery::class, StateChangeStream::class, ServiceTarget::class, TypedEntity::class] as $import) {
            $context->importClass($namespace, $import, $declared);
        }

        if ($domain->handleServices->containsWhere(static fn(ServiceModel $service): bool => $service->definition->returnsResponse)) {
            $context->importClass($namespace, ServiceResponse::class, $declared);
        }

        $class = $namespace->addClass($declared)
            ->setFinal()
            ->setReadOnly()
            ->addImplement(TypedEntity::class)
            ->addComment(\sprintf('One `%s` entity.', $domain->domain));

        $class->addConstant(self::ENTITY_IDS_CONSTANT, array_fill_keys($domain->entityIds->toStrings(), true))
            ->setType('array')
            ->setPrivate();

        $class->addProperty('entity')->setType(Entity::class)->setPrivate();

        $constructor = $class->addMethod('__construct')->addComment('@throws ' . $identifierException);
        $constructor->addParameter('ha')->setType(HaContext::class);
        $constructor->addPromotedParameter('id')->setType(EntityId::class)->setPublic();
        $constructor->setBody(\sprintf(
            "if (!self::isGeneratedEntityId(\$id)) {\n    throw %s::entityNotGenerated(\$id->value, self::getDomain());\n}\n\n\$this->entity = \$ha->getEntity(\$id);",
            $identifierException,
        ));

        $class->addMethod('getDomain')
            ->setStatic()
            ->setReturnType('string')
            ->setBody('return ?;', [$domain->domain]);

        $class->addMethod('isGeneratedEntityId')
            ->setStatic()
            ->setReturnType('bool')
            ->setBody(\sprintf('return isset(self::%s[$id->value]);', self::ENTITY_IDS_CONSTANT))
            ->addParameter('id')
            ->setType(EntityId::class);

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

        $class->addMethod('getHistory')
            ->setReturnType(EntityStateHistory::class)
            ->addComment('@throws ' . $historyException)
            ->setBody('return $this->entity->getHistory($query);')
            ->addParameter('query')
            ->setType(HistoryQuery::class);

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
