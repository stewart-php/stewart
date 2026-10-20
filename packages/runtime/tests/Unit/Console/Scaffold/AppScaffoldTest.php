<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console\Scaffold;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Console\Scaffold\AppScaffold;
use Stewart\Runtime\Exception\ScaffoldError;
use Stewart\Runtime\Tests\Fixtures\Apps\TemporaryAppsDirectory;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AppScaffold::class)]
final class AppScaffoldTest extends TestCase
{
    use AssertsReason;

    private TemporaryAppsDirectory $apps;

    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->apps = new TemporaryAppsDirectory();
        $this->project = TempDirectory::createWithPrefix('stewart-scaffold-');
    }

    protected function tearDown(): void
    {
        $this->apps->remove();
        $this->project->remove();
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideClassNames(): iterable
    {
        yield 'one word' => ['Thermostat', 'thermostat'];
        yield 'two words' => ['PorchLight', 'porch-light'];
        yield 'leading acronym' => ['HTTPProbe', 'http-probe'];
        yield 'digits' => ['TV2Remote', 'tv2-remote'];
    }

    #[DataProvider('provideClassNames')]
    public function testIdIsDerivedFromTheClassName(string $className, string $expectedId): void
    {
        self::assertSame($expectedId, $this->createScaffold()->scaffoldApp($className)->id->value);
    }

    public function testWrittenAppIsDiscoveredUnderItsId(): void
    {
        $this->createScaffold()->scaffoldApp('PorchLight');

        self::assertSame(['porch-light'], $this->apps->createDiscovery()->discover()->apps->listAppIds()->toStrings());
    }

    public function testIdOptionReplacesTheDerivedId(): void
    {
        $this->createScaffold()->scaffoldApp('PorchLight', 'porch');

        self::assertSame(['porch'], $this->apps->createDiscovery()->discover()->apps->listAppIds()->toStrings());
    }

    public function testTestGoesToTheProjectTestsDirectoryWhenNoneIsMapped(): void
    {
        $app = $this->createScaffold()->scaffoldApp('PorchLight');

        self::assertSame('tests/PorchLightTest.php', $app->testFile);
        self::assertStringContainsString('namespace ' . $this->apps->namespace . '\\Tests;', (string) file_get_contents($this->project->getFilePath('tests/PorchLightTest.php')));
    }

    public function testTestGoesToTheMappedTestsDirectory(): void
    {
        $psr4Map = new Psr4Map([
            $this->apps->namespace . '\\' => [$this->apps->path],
            $this->apps->namespace . '\\Tests\\' => [$this->project->getFilePath('spec')],
        ]);

        self::assertSame('spec/PorchLightTest.php', $this->createScaffold($psr4Map)->scaffoldApp('PorchLight')->testFile);
    }

    public function testInvalidClassNameIsRefused(): void
    {
        $this->assertThrowsReason(ScaffoldError::ClassNameInvalid, fn() => $this->createScaffold()->scaffoldApp('porchLight'));
    }

    public function testExistingAppFileIsRefused(): void
    {
        $this->apps->writeClass('PorchLight', 'final class PorchLight {}');

        $this->assertThrowsReason(ScaffoldError::FileExists, fn() => $this->createScaffold()->scaffoldApp('PorchLight'));
    }

    public function testIdOfAnotherAppIsRefused(): void
    {
        $this->apps->writeClass('Lamp', <<<'PHP'
            use Stewart\Contracts\App;
            use Stewart\Contracts\Automation;

            #[Automation(id: 'porch_light')]
            final class Lamp implements App
            {
                public function initialize(): void {}

                public function dispose(): void {}
            }
            PHP);

        $e = $this->assertThrowsReason(ScaffoldError::IdTaken, fn() => $this->createScaffold()->scaffoldApp('PorchLight'));

        self::assertSame('porch_light', $e->context['appId'] ?? null);
    }

    private function createScaffold(?Psr4Map $psr4Map = null): AppScaffold
    {
        return new AppScaffold(
            $psr4Map ?? new Psr4Map([$this->apps->namespace . '\\' => [$this->apps->path]]),
            new ProjectRoot($this->project->path),
            $this->apps->createDiscovery(),
            new Filesystem(),
            $this->apps->path,
        );
    }
}
