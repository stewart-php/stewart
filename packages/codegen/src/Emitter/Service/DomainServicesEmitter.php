<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Service;

use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;

final readonly class DomainServicesEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['ha'];

    public function __construct(private ServiceMethodEmitter $methods) {}

    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasServices();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, HaContext::class, $domain->getServicesClass());

        if ($this->anyReturnsResponse($domain)) {
            $context->importClass($namespace, ServiceResponse::class, $domain->getServicesClass());
        }

        if ($this->anyTargetable($domain)) {
            $context->importClass($namespace, ServiceTargetSource::class, $domain->getServicesClass());
        }

        $class = $namespace->addClass($domain->getServicesClass())
            ->setFinal()
            ->setReadOnly()
            ->addComment(\sprintf('The `%s` services.', $domain->domain));

        $class->addMethod('__construct')
            ->addPromotedParameter('ha')
            ->setType(HaContext::class)
            ->setPrivate();

        foreach ($domain->services as $service) {
            $this->methods->addDomainServiceMethod($class, $service);
        }

        return $context->printFile($namespace, $domain->getServicesClass());
    }

    private function anyTargetable(DomainModel $domain): bool
    {
        foreach ($domain->services as $service) {
            if ($service->definition->target->isTargetable) {
                return true;
            }
        }

        return false;
    }

    private function anyReturnsResponse(DomainModel $domain): bool
    {
        foreach ($domain->services as $service) {
            if ($service->definition->returnsResponse) {
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
