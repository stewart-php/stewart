<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output\Collection;

use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\FileOutcome;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<FileChange> */
final readonly class FileChangeCollection extends ListCollection
{
    /** @param iterable<FileChange> $changes */
    public static function fromChangesSortedByPath(iterable $changes): self
    {
        return self::fromList($changes)->sortedBy(static fn(FileChange $a, FileChange $b): int => strcmp($a->path, $b->path));
    }

    public function filterStale(): self
    {
        return $this->filter(static fn(FileChange $change): bool => $change->outcome->isChange());
    }

    public function countWithOutcome(FileOutcome $outcome): int
    {
        return $this->filter(static fn(FileChange $change): bool => $change->outcome === $outcome)->count();
    }

    public function countExistingFiles(): int
    {
        return $this->filter(static fn(FileChange $change): bool => $change->outcome !== FileOutcome::Created)->count();
    }

    public function isClean(): bool
    {
        return !$this->containsWhere(static fn(FileChange $change): bool => $change->outcome->isChange());
    }
}
