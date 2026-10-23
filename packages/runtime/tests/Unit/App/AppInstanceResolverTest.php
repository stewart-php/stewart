<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppClassReflection;
use Stewart\Runtime\App\AppInstanceResolver;
use Stewart\Runtime\App\AppOrigin;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\Config\AppOverride;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broken\TemplateAndAutomation\BothMarked;
use Stewart\Runtime\Tests\Fixtures\Broken\Unmarked\Forgotten;
use Stewart\Runtime\Tests\Fixtures\Templates\RoomLight;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(AppInstanceResolver::class)]
#[CoversClass(AppClassReflection::class)]
#[CoversClass(DiscoveryResult::class)]
final class AppInstanceResolverTest extends TestCase
{
    use AssertsReason;

    public function testEntriesWithoutAClassChangeNothing(): void
    {
        $discovered = self::createDiscovery();

        self::assertSame($discovered, self::createResolver()->resolveInstances($discovered, self::createOverrides(['demo' => null])));
    }

    public function testEachEntryRunsTheTemplateUnderItsOwnId(): void
    {
        $resolved = self::createResolver()->resolveInstances(self::createDiscovery(), self::createOverrides([
            'hall-light' => RoomLight::class,
            'landing-light' => RoomLight::class,
        ]));

        self::assertSame(['demo', 'hall-light', 'landing-light'], $resolved->apps->listAppIds()->toStrings());
        self::assertSame(
            [[Demo::class, AppOrigin::Scanned], [RoomLight::class, AppOrigin::Configured], [RoomLight::class, AppOrigin::Configured]],
            $resolved->apps->mapToList(static fn(DiscoveredApp $app): array => [$app->class, $app->origin]),
        );
        self::assertSame([], $resolved->listUnusedTemplateClasses());
    }

    public function testAnAutomationRunsOnceMoreUnderAnotherId(): void
    {
        $resolved = self::createResolver()->resolveInstances(self::createDiscovery(), self::createOverrides(['other-demo' => Demo::class]));

        self::assertSame(['demo', 'other-demo'], $resolved->apps->listAppIds()->toStrings());
        self::assertSame([RoomLight::class], $resolved->listUnusedTemplateClasses());
    }

    public function testAMistypedClassNamesTheClosestOne(): void
    {
        $e = $this->assertThrowsReason(AppError::InstanceClassMissing, static fn() => self::createResolver()->resolveInstances(
            self::createDiscovery(),
            self::createOverrides(['hall-light' => 'Stewart\\Runtime\\Tests\\Fixtures\\Templates\\RoomLite']),
        ));

        self::assertStringEndsWith('Did you mean ' . RoomLight::class . '?', $e->getMessage());
    }

    public function testAClassWithoutEitherAttributeIsRefused(): void
    {
        $this->assertThrowsReason(AppError::InstanceClassNotMarked, static fn() => self::createResolver()->resolveInstances(self::createDiscovery(), self::createOverrides(['forgotten' => Forgotten::class])));
    }

    public function testAClassWithBothAttributesIsRefused(): void
    {
        $this->assertThrowsReason(AppError::TemplateAndAutomation, static fn() => self::createResolver()->resolveInstances(self::createDiscovery(), self::createOverrides(['both' => BothMarked::class])));
    }

    public function testTheIdOfAnAutomationIsTaken(): void
    {
        $e = $this->assertThrowsReason(AppError::InstanceIdTaken, static fn() => self::createResolver()->resolveInstances(self::createDiscovery(), self::createOverrides(['demo' => RoomLight::class])));

        self::assertStringContainsString('already the ID of automation ' . Demo::class, $e->getMessage());
    }

    public function testTwoEntriesWhoseIdsFoldTogetherCollide(): void
    {
        $this->assertThrowsReason(AppError::InstanceIdsCollide, static fn() => self::createResolver()->resolveInstances(
            self::createDiscovery(),
            self::createOverrides(['hall-light' => RoomLight::class, 'hall_light' => RoomLight::class]),
        ));
    }

    private static function createResolver(): AppInstanceResolver
    {
        return new AppInstanceResolver(new ClosestNameFinder());
    }

    private static function createDiscovery(): DiscoveryResult
    {
        return new DiscoveryResult(
            DiscoveredAppCollection::fromApps([new DiscoveredApp(Demo::class, new AppId('demo'))]),
            UnmarkedAppCollection::empty(),
            UnloadableAppFileCollection::empty(),
            [RoomLight::class],
        );
    }

    /** @param array<string, ?string> $classesById */
    private static function createOverrides(array $classesById): AppOverrideCollection
    {
        $overrides = [];

        foreach ($classesById as $id => $class) {
            $overrides[] = new AppOverride(new AppId($id), enabled: true, paused: false, worker: null, options: [], class: $class);
        }

        return AppOverrideCollection::keyedByAppId($overrides);
    }
}
