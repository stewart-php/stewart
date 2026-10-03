<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

final readonly class AttributeModel
{
    public function __construct(
        public string $accessor,
        public string $name,
        public AttributeKind $kind,
    ) {}
}
