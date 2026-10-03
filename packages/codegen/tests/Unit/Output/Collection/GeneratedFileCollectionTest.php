<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Output\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Output\Collection\GeneratedFileCollection;
use Stewart\Codegen\Output\GeneratedFile;

#[CoversClass(GeneratedFileCollection::class)]
final class GeneratedFileCollectionTest extends TestCase
{
    public function testSortsByPath(): void
    {
        $files = GeneratedFileCollection::fromFilesSortedByPath([
            new GeneratedFile('b.php', 'b'),
            new GeneratedFile('a.php', 'a'),
        ]);

        self::assertSame(['a.php', 'b.php'], $files->mapToList(static fn(GeneratedFile $file): string => $file->path));
    }
}
