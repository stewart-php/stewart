<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\App;

use Error;
use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\App\UnloadableAppFile;
use Stewart\Runtime\App\UnmarkedApp;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Tests\Fixtures\Apps\AppDiscoveryFixture;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Apps\Nested\HallLight;
use Stewart\Runtime\Tests\Fixtures\Apps\TemporaryAppsDirectory;
use Stewart\Runtime\Tests\Fixtures\Broken\Unmarked\Forgotten;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(AppDiscovery::class)]
#[CoversClass(DiscoveredApp::class)]
#[CoversClass(DiscoveryResult::class)]
#[CoversClass(UnmarkedApp::class)]
#[CoversClass(UnmarkedAppCollection::class)]
#[CoversClass(UnloadableAppFile::class)]
#[CoversClass(UnloadableAppFileCollection::class)]
final class AppDiscoveryTest extends TestCase
{
    use AssertsReason;

    private const string SURVIVOR_SOURCE = <<<'PHP'
        use Stewart\Contracts\App;
        use Stewart\Contracts\Automation;

        #[Automation(id: 'survivor')]
        final class Survivor implements App
        {
            public function initialize(): void {}

            public function dispose(): void {}
        }
        PHP;

    private ?TemporaryAppsDirectory $temporary = null;

    protected function tearDown(): void
    {
        $this->temporary?->remove();
        $this->temporary = null;
    }

    public function testFileWithSyntaxErrorIsSkippedAndReported(): void
    {
        $this->temporary = new TemporaryAppsDirectory();
        $this->temporary->writeClass('Broken', 'final class Broken {');

        $found = $this->temporary->createDiscovery()->discover();

        self::assertTrue($found->apps->isEmpty());
        self::assertSame([realpath($this->temporary->path . '/Broken.php')], $found->unloadable->listPaths());
        self::assertInstanceOf(ParseError::class, $found->unloadable->getFirst()?->cause);
    }

    public function testClassWithMissingParentIsSkipped(): void
    {
        $this->temporary = new TemporaryAppsDirectory();
        $this->temporary->writeClass('Orphan', 'final class Orphan extends MissingParent {}');

        $cause = $this->temporary->createDiscovery()->discover()->unloadable->getFirst()?->cause;

        self::assertInstanceOf(Error::class, $cause);
        self::assertStringContainsString('MissingParent', $cause->getMessage());
    }

    public function testLoadableAppsSurviveAnUnloadableFile(): void
    {
        $this->temporary = new TemporaryAppsDirectory();
        $this->temporary->writeClass('Broken', 'final class Broken {');
        $this->temporary->writeClass('Survivor', self::SURVIVOR_SOURCE);

        $found = $this->temporary->createDiscovery()->discover();

        self::assertSame(['survivor'], $found->apps->listAppIds()->toStrings());
        self::assertSame(1, $found->unloadable->count());
    }

    public function testItFindsMarkedClassesIncludingNestedOnes(): void
    {
        $found = AppDiscoveryFixture::createForFixtureDirectory()->discover();

        self::assertSame(['demo', 'echo', 'hall-light'], $found->apps->listAppIds()->toStrings());
        self::assertSame(Demo::class, $found->apps->listValues()[0]->class);
        self::assertSame(HallLight::class, $found->apps->listValues()[2]->class);
    }

    public function testOrderIsSortedById(): void
    {
        $ids = AppDiscoveryFixture::createForFixtureDirectory()->discover()->apps->listAppIds()->toStrings();
        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, $ids);
    }

    public function testUnmarkedAppIsReported(): void
    {
        $found = AppDiscoveryFixture::createForFixtureDirectory('Broken/Unmarked')->discover();

        self::assertTrue($found->apps->isEmpty());
        self::assertSame([Forgotten::class], $found->unmarked->listClasses());
    }

    public function testTwoAutomationsCannotShareAnId(): void
    {
        $e = $this->assertThrowsReason(AppError::DuplicateId, fn() => AppDiscoveryFixture::createForFixtureDirectory('Broken/Duplicate')->discover());

        self::assertMatchesRegularExpression('/both use ID "twice"/', $e->getMessage());
    }

    public function testNumericIdIsRefusedAtDiscovery(): void
    {
        $e = $this->assertThrowsReason(AppError::IdInvalid, fn() => AppDiscoveryFixture::createForFixtureDirectory('Broken/NumericId')->discover());

        self::assertMatchesRegularExpression('/automation ID "123" is invalid/', $e->getMessage());
    }

    public function testUppercaseIdIsRefusedAtDiscovery(): void
    {
        $e = $this->assertThrowsReason(AppError::IdInvalid, fn() => AppDiscoveryFixture::createForFixtureDirectory('Broken/Uppercase')->discover());

        self::assertMatchesRegularExpression('/automation ID "HallLight" is invalid/', $e->getMessage());
    }

    public function testHyphenAndUnderscoreIdsCollide(): void
    {
        $e = $this->assertThrowsReason(AppError::DuplicateId, fn() => AppDiscoveryFixture::createForFixtureDirectory('Broken/Comparable')->discover());

        self::assertMatchesRegularExpression('/both use ID/', $e->getMessage());
    }

    public function testMarkedClassMustImplementApp(): void
    {
        $e = $this->assertThrowsReason(AppError::NotAnApp, fn() => AppDiscoveryFixture::createForFixtureDirectory('Broken/NotAnApp')->discover());

        self::assertMatchesRegularExpression('/does not implement/', $e->getMessage());
    }

    public function testMissingDirectoryFindsNothing(): void
    {
        $found = AppDiscoveryFixture::createForFixtureDirectory('DoesNotExist')->discover();

        self::assertTrue($found->apps->isEmpty());
        self::assertTrue($found->apps->listAppIds()->isEmpty());
    }

    public function testDirectoryOutsideTheAutoloaderSaysSo(): void
    {
        $e = $this->assertThrowsReason(AppError::DirectoryNotAutoloadable, fn() => new AppDiscovery(new Psr4Map([]), \dirname(__DIR__, 2) . '/Fixtures/Apps')->discover());

        self::assertMatchesRegularExpression('/not covered by a PSR-4 autoload rule/', $e->getMessage());
    }
}
