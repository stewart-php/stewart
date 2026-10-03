<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Entity;

use Stewart\Codegen\Emitter\DomainFileEmitter;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Model\DomainModel;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\State\EntityState;

final readonly class EntityStateEmitter implements DomainFileEmitter, ReservesMemberNames
{
    private const array RESERVED_MEMBER_NAMES = ['raw', 'value', 'fromNullableState', 'isOn', 'isOff', 'getStateAsFloat', 'isUnavailable', 'getFriendlyName'];

    public function supportsDomain(DomainModel $domain): bool
    {
        return $domain->hasEntities();
    }

    public function emitFile(DomainModel $domain, EmitContext $context): GeneratedFile
    {
        $namespace = $context->createNamespace();
        $context->importClass($namespace, EntityState::class, $domain->getStateClass());

        $class = $namespace->addClass($domain->getStateClass())
            ->setFinal()
            ->setReadOnly()
            ->addComment(\sprintf('What a `%s` entity is doing.', $domain->domain));

        $class->addProperty('value')->setType('string');

        $constructor = $class->addMethod('__construct');
        $constructor->addPromotedParameter('raw')->setType(EntityState::class)->setPublic();
        $constructor->setBody('$this->value = $raw->state;');

        $class->addMethod('fromNullableState')
            ->setStatic()
            ->setReturnType('?self')
            ->setBody('return $raw === null ? null : new self($raw);')
            ->addParameter('raw')
            ->setType('?' . EntityState::class);

        if ($domain->isOnOff()) {
            $class->addMethod('isOn')->setReturnType('bool')->setBody("return \$this->value === 'on';");
            $class->addMethod('isOff')->setReturnType('bool')->setBody("return \$this->value === 'off';");
        }

        if ($domain->isNumeric()) {
            $class->addMethod('getStateAsFloat')->setReturnType('?float')->setBody('return $this->raw->getStateAsFloat();');
        }

        $class->addMethod('isUnavailable')->setReturnType('bool')->setBody('return $this->raw->isUnavailable();');
        $class->addMethod('getFriendlyName')->setReturnType('string')->setBody('return $this->raw->getFriendlyName();');

        foreach ($domain->attributes as $attribute) {
            $type = $attribute->kind->getPhpType();
            $method = $class->addMethod($attribute->accessor)
                ->setReturnType($type->native)
                ->setBody(\sprintf(
                    'return $this->raw->%s(%s);',
                    $attribute->kind->getReaderMethodName(),
                    var_export($attribute->name, true),
                ));

            if ($type->needsDocblock()) {
                $method->addComment('@return ' . $type->docblock);
            }
        }

        return $context->printFile($namespace, $domain->getStateClass());
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === MemberScope::StateView ? self::RESERVED_MEMBER_NAMES : [];
    }
}
