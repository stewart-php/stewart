<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\FieldModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<FieldModel> */
final readonly class FieldModelCollection extends ListCollection
{
    /** @param iterable<FieldModel> $fields */
    public static function fromFields(iterable $fields): self
    {
        return self::fromList($fields);
    }
}
