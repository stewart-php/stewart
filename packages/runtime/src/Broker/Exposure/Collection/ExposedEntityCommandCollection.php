<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;

/** @extends ListCollection<ExposedEntityCommand> */
final readonly class ExposedEntityCommandCollection extends ListCollection
{
    /** @param iterable<ExposedEntityCommand> $commands */
    public static function fromCommands(iterable $commands): self
    {
        return self::fromList($commands);
    }
}
