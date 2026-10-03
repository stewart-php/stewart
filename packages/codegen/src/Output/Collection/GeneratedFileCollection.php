<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output\Collection;

use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<GeneratedFile> */
final readonly class GeneratedFileCollection extends ListCollection
{
    /** @param iterable<GeneratedFile> $files */
    public static function fromFilesSortedByPath(iterable $files): self
    {
        return self::fromList($files)->sortedBy(static fn(GeneratedFile $a, GeneratedFile $b): int => strcmp($a->path, $b->path));
    }
}
