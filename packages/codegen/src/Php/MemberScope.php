<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

enum MemberScope
{
    case RootClasses;
    case ClassStems;
    case EntityHandle;
    case DomainServices;
    case StateView;
    case Parameters;
    case TargetedParameters;

    public function getCollisionSuffix(): string
    {
        return match ($this) {
            self::RootClasses, self::ClassStems => 'Domain',
            self::EntityHandle, self::DomainServices => 'Service',
            self::StateView => 'Attribute',
            self::Parameters, self::TargetedParameters => 'Field',
        };
    }
}
