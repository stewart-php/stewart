<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

final readonly class ServiceModel
{
    /** @param list<FieldModel> $fields */
    public function __construct(
        public string $method,
        public ServiceDefinition $definition,
        public array $fields,
    ) {}
}
