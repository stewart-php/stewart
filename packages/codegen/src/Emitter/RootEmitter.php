<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter;

use Stewart\Codegen\Model\Collection\DomainModelCollection;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Model\GenerationModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\HaContext;

abstract readonly class RootEmitter implements ModelFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['ha'];

    public function emitFile(GenerationModel $model, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, HaContext::class, $this->getClassName());

        $class = $namespace->addClass($this->getClassName())
            ->setFinal()
            ->addComment($this->getClassComment());

        $class->addMethod('__construct')
            ->addPromotedParameter('ha')
            ->setType(HaContext::class)
            ->setPrivate()
            ->setReadOnly();

        foreach ($this->listDomains($model) as $domain) {
            $domainClass = $this->getDomainClassName($domain);

            $class->addProperty($domain->accessor)
                ->setType($context->resolveClassName($domainClass))
                ->addComment($this->buildPropertyComment($domain->domain))
                ->addHook('get', \sprintf('new %s($this->ha)', $domainClass));
        }

        return $context->printFile($namespace, $this->getClassName());
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::RootClasses ? self::RESERVED_MEMBER_NAMES : [];
    }

    abstract protected function getClassName(): string;

    abstract protected function getClassComment(): string;

    abstract protected function listDomains(GenerationModel $model): DomainModelCollection;

    abstract protected function getDomainClassName(DomainModel $domain): string;

    abstract protected function buildPropertyComment(string $domain): string;
}
