<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Json\FieldShape;

/** @extends ListCollection<FieldShape> */
final readonly class FieldShapeCollection extends ListCollection
{
    /** @param iterable<FieldShape> $fields */
    public static function fromFieldShapes(iterable $fields): self
    {
        return self::fromList($fields);
    }
}
