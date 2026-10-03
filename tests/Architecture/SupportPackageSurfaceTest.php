<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class SupportPackageSurfaceTest extends TestCase
{
    private const string NAMESPACE = 'Stewart\\Support\\';

    private const string SOURCE_DIRECTORY = 'packages/support/src';

    public function testEveryClassInTheSupportPackageIsTaggedInternal(): void
    {
        $classes = self::listSupportClasses();
        $violations = [];

        foreach ($classes as $class) {
            if (!self::isTaggedInternal($class)) {
                $violations[] = $class . ' lives in the support package but is not tagged @internal';
            }
        }

        self::assertNotEmpty($classes, 'No support classes found; the scan is broken.');
        self::assertEmpty($violations, implode("\n", $violations));
    }

    /** @return list<class-string> */
    private static function listSupportClasses(): array
    {
        $repository = new RepositoryFiles();
        $classes = [];

        foreach ($repository->listFilesIn([self::SOURCE_DIRECTORY]) as $file) {
            $relative = substr($repository->toRelativePath($file), \strlen(self::SOURCE_DIRECTORY) + 1, -\strlen('.php'));
            $class = self::NAMESPACE . str_replace('/', '\\', $relative);

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @param class-string $class */
    private static function isTaggedInternal(string $class): bool
    {
        $docComment = new ReflectionClass($class)->getDocComment();

        return $docComment !== false && preg_match('/@internal\b/', $docComment) === 1;
    }
}
