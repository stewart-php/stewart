<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Output\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Output\Collection\FileChangeCollection;
use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\FileOutcome;

#[CoversClass(FileChangeCollection::class)]
final class FileChangeCollectionTest extends TestCase
{
    public function testSortsByPath(): void
    {
        $changes = FileChangeCollection::fromChangesSortedByPath([
            new FileChange('b.php', FileOutcome::Created),
            new FileChange('a.php', FileOutcome::Unchanged),
        ]);

        self::assertSame(['a.php', 'b.php'], $changes->mapToList(static fn(FileChange $change): string => $change->path));
    }

    public function testStaleLeavesOutUnchangedFiles(): void
    {
        $changes = FileChangeCollection::fromChangesSortedByPath([
            new FileChange('a.php', FileOutcome::Unchanged),
            new FileChange('b.php', FileOutcome::Updated),
            new FileChange('c.php', FileOutcome::Deleted),
        ]);

        self::assertEquals(
            [new FileChange('b.php', FileOutcome::Updated), new FileChange('c.php', FileOutcome::Deleted)],
            $changes->filterStale()->listValues(),
        );
        self::assertFalse($changes->isClean());
    }

    public function testCountsPerOutcome(): void
    {
        $changes = FileChangeCollection::fromChangesSortedByPath([
            new FileChange('a.php', FileOutcome::Created),
            new FileChange('b.php', FileOutcome::Created),
            new FileChange('c.php', FileOutcome::Unchanged),
        ]);

        self::assertSame(2, $changes->countWithOutcome(FileOutcome::Created));
        self::assertSame(1, $changes->countWithOutcome(FileOutcome::Unchanged));
        self::assertSame(0, $changes->countWithOutcome(FileOutcome::Deleted));
    }

    public function testOnlyUnchangedFilesIsClean(): void
    {
        self::assertTrue(FileChangeCollection::fromChangesSortedByPath([new FileChange('a.php', FileOutcome::Unchanged)])->isClean());
        self::assertTrue(FileChangeCollection::fromChangesSortedByPath([])->isClean());
    }
}
