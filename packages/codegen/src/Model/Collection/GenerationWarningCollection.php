<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model\Collection;

use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<GenerationWarning> */
final readonly class GenerationWarningCollection extends ListCollection
{
    /** @param iterable<GenerationWarning> $warnings */
    public static function fromWarnings(iterable $warnings): self
    {
        return self::fromList($warnings);
    }
}
