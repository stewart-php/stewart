<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Runtime\Json\Collection\FieldShapeCollection;

final readonly class ClassShape
{
    public bool $holdsFragment;

    /** @param class-string $class */
    public function __construct(
        public string $class,
        public FieldShapeCollection $fields,
    ) {
        $this->holdsFragment = $fields->containsWhere(static fn(FieldShape $field): bool => $field->value->kind === ValueKind::Fragment);
    }
}
