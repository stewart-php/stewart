<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Registry\Collection\AreaIdCollection;
use Stewart\Contracts\Registry\Collection\DeviceIdCollection;
use Stewart\Contracts\Registry\Collection\FloorIdCollection;
use Stewart\Contracts\Registry\RegistryNames;
use Stewart\Contracts\Trigger\TriggerJson;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Contracts\Wire\WireVariants;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class InternalSurfaceTest extends TestCase
{
    private const array INTERNALS_HELD_BY_APP_FACING_CONTRACTS = [
        AppIdCollection::class,
        CalendarStateFormat::class,
        AreaIdCollection::class,
        DeviceIdCollection::class,
        FloorIdCollection::class,
        RegistryNames::class,
        TriggerJson::class,
        ListOf::class,
        WireVariants::class,
    ];

    public function testEveryClassInTheSupportPackageIsTaggedInternal(): void
    {
        $classes = self::listClassesIn('packages/support/src', 'Stewart\\Support\\');
        $violations = [];

        foreach ($classes as $class) {
            if (!self::isTaggedInternal($class)) {
                $violations[] = $class . ' lives in the support package but is not tagged @internal';
            }
        }

        self::assertNotEmpty($classes, 'No support classes found; the scan is broken.');
        self::assertEmpty($violations, implode("\n", $violations));
    }

    public function testContractsTagsOnlyTheHeldClassesInternal(): void
    {
        $internals = array_values(array_filter(
            self::listClassesIn('packages/contracts/src', 'Stewart\\Contracts\\'),
            self::isTaggedInternal(...),
        ));

        self::assertEqualsCanonicalizing(
            self::INTERNALS_HELD_BY_APP_FACING_CONTRACTS,
            $internals,
            'A new internal class belongs in packages/support; keep contracts for the app-facing API.',
        );
    }

    /** @return list<class-string> */
    private static function listClassesIn(string $sourceDirectory, string $namespace): array
    {
        $repository = new RepositoryFiles();
        $classes = [];

        foreach ($repository->listFilesIn([$sourceDirectory]) as $file) {
            $relative = substr($repository->toRelativePath($file), \strlen($sourceDirectory) + 1, -\strlen('.php'));
            $class = $namespace . str_replace('/', '\\', $relative);

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
