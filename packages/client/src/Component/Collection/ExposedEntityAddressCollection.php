<?php

declare(strict_types=1);

namespace Stewart\Client\Component\Collection;

use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ExposedEntityAddress> */
final readonly class ExposedEntityAddressCollection extends ListCollection
{
    /** @param iterable<ExposedEntityAddress> $addresses */
    public static function fromAddresses(iterable $addresses): self
    {
        return self::fromList($addresses);
    }
}
