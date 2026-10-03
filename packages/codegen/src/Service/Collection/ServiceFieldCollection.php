<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\ServiceField;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ServiceField> */
final readonly class ServiceFieldCollection extends ListCollection
{
    /** @param iterable<ServiceField> $fields */
    public static function fromFields(iterable $fields): self
    {
        return self::fromList($fields);
    }
}
