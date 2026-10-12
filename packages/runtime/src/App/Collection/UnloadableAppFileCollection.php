<?php

declare(strict_types=1);

namespace Stewart\Runtime\App\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\App\UnloadableAppFile;

/** @extends ListCollection<UnloadableAppFile> */
final readonly class UnloadableAppFileCollection extends ListCollection
{
    /** @return list<string> */
    public function listPaths(): array
    {
        return $this->mapToList(static fn(UnloadableAppFile $file): string => $file->path);
    }
}
