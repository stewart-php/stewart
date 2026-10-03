<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute\Collection;

use Stewart\Codegen\Attribute\AttributeModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<AttributeModel> */
final readonly class AttributeModelCollection extends ListCollection
{
    /** @param iterable<AttributeModel> $attributes */
    public static function fromAttributes(iterable $attributes): self
    {
        return self::fromList($attributes);
    }
}
