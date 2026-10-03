<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Service\Collection\FieldModelCollection;

final readonly class ServiceModel
{
    public function __construct(
        public string $method,
        public ServiceDefinition $definition,
        public FieldModelCollection $fields,
    ) {}
}
