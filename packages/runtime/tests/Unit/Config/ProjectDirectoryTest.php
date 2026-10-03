<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ProjectDirectory;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ProjectDirectory::class)]
final class ProjectDirectoryTest extends TestCase
{
    use AssertsReason;

    public function testTrailingSlashIsDroppedAndTheRootIsJoinedOn(): void
    {
        $directory = new ProjectDirectory('src/generated/');

        self::assertSame('src/generated', $directory->value);
        self::assertSame('/app/src/generated', $directory->resolveWithin('/app'));
    }

    #[DataProvider('providePathsOutsideProject')]
    public function testPathOutsideTheProjectIsRefused(string $directory): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => new ProjectDirectory($directory));

        self::assertStringContainsString('is not a path inside the project', $e->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function providePathsOutsideProject(): iterable
    {
        yield 'parent' => ['../elsewhere'];
        yield 'parent in the middle' => ['src/../../elsewhere'];
        yield 'absolute' => ['/tmp/generated'];
        yield 'empty' => [''];
        yield 'only a slash' => ['/'];
    }
}
