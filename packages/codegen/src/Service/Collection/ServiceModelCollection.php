<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\ServiceModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ServiceModel> */
final readonly class ServiceModelCollection extends ListCollection
{
    /** @param iterable<ServiceModel> $services */
    public static function fromServices(iterable $services): self
    {
        return self::fromList($services);
    }
}
