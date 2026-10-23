<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Action;

use Stewart\Codegen\Action\ActionModel;
use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTargetSource;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\EventContext;

final readonly class DomainServicesEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['ha'];

    public function __construct(private ActionMethodEmitter $methods) {}

    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasActions();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, HaContext::class, $domain->getServicesClass());

        if ($this->anyReturnsResponse($domain)) {
            $context->importClass($namespace, ActionResponse::class, $domain->getServicesClass());
        }

        if (array_any($domain->actions, static fn(ActionModel $action): bool => !$action->definition->returnsResponse)) {
            $context->importClass($namespace, EventContext::class, $domain->getServicesClass());
        }

        if ($this->anyTargetable($domain)) {
            $context->importClass($namespace, ActionTargetSource::class, $domain->getServicesClass());
        }

        $class = $namespace->addClass($domain->getServicesClass())
            ->setFinal()
            ->setReadOnly()
            ->addComment(\sprintf('The `%s` services.', $domain->domain));

        $class->addMethod('__construct')
            ->addPromotedParameter('ha')
            ->setType(HaContext::class)
            ->setPrivate();

        foreach ($domain->actions as $action) {
            $this->methods->addDomainActionMethod($class, $action);
        }

        return $context->printFile($namespace, $domain->getServicesClass());
    }

    private function anyTargetable(DomainModel $domain): bool
    {
        foreach ($domain->actions as $action) {
            if ($action->definition->target->isTargetable) {
                return true;
            }
        }

        return false;
    }

    private function anyReturnsResponse(DomainModel $domain): bool
    {
        foreach ($domain->actions as $action) {
            if ($action->definition->returnsResponse) {
                return true;
            }
        }

        return false;
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::DomainServices ? self::RESERVED_MEMBER_NAMES : [];
    }
}
