<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action;

final readonly class ActionModel
{
    /** @param list<FieldModel> $fields */
    public function __construct(
        public string $method,
        public ActionDefinition $definition,
        public array $fields,
    ) {}
}
