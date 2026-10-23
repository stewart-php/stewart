<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Entity;

use Stewart\Codegen\Action\ActionModel;
use Stewart\Codegen\Emitter\Action\ActionMethodEmitter;
use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Time\Duration;

final readonly class EntityHandleEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['id', 'getDomain', 'isGeneratedEntityId', 'getState', 'requireState', 'watchStateChanges', 'getHistory', 'waitForState', 'listAttributes', 'getEntity', 'toActionTarget', 'ha'];

    private const string ENTITY_IDS_CONSTANT = 'GENERATED_ENTITY_IDS';

    public function __construct(private ActionMethodEmitter $actionMethods) {}

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
        $waitException = $context->importClass($namespace, WaitException::class, $declared);

        foreach ([Entity::class, EntityId::class, HaContext::class, EntityStateHistory::class, HistoryQuery::class, StateChangeStream::class, ActionTarget::class, TypedEntity::class, Duration::class] as $import) {
            $context->importClass($namespace, $import, $declared);
        }

        if (array_any($domain->handleActions, static fn(ActionModel $action): bool => $action->definition->returnsResponse)) {
            $context->importClass($namespace, ActionResponse::class, $declared);
        }

        if (array_any($domain->handleActions, static fn(ActionModel $action): bool => !$action->definition->returnsResponse)) {
            $context->importClass($namespace, EventContext::class, $declared);
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

        $waitForState = $class->addMethod('waitForState')
            ->setReturnType('?' . $context->resolveClassName($domain->getStateClass()))
            ->addComment('@throws ' . $waitException)
            ->setBody(\sprintf('return %s::fromNullableState($this->entity->waitForState($state, $timeout));', $domain->getStateClass()));
        $waitForState->addParameter('state')->setType('string');
        $waitForState->addParameter('timeout', null)->setType(Duration::class)->setNullable();

        $class->addMethod('listAttributes')
            ->setReturnType('array')
            ->addComment('@return array<string, mixed>')
            ->setBody("\$state = \$this->entity->getState();\n\nreturn \$state === null ? [] : \$state->attributes;");

        $class->addMethod('getEntity')
            ->setReturnType(Entity::class)
            ->setBody('return $this->entity;');

        $class->addMethod('toActionTarget')
            ->setReturnType(ActionTarget::class)
            ->setBody('return $this->entity->toActionTarget();');

        foreach ($domain->handleActions as $action) {
            $this->actionMethods->addHandleMethod($class, $action);
        }

        return $context->printFile($namespace, $declared);
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::EntityHandle ? self::RESERVED_MEMBER_NAMES : [];
    }
}
