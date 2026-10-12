<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

final readonly class ClassShape
{
    public bool $holdsFragment;

    /**
     * @param class-string $class
     * @param list<FieldShape> $fields
     */
    public function __construct(
        public string $class,
        public array $fields,
    ) {
        $this->holdsFragment = array_any($fields, static fn(FieldShape $field): bool => $field->value->kind === ValueKind::Fragment);
    }
}
