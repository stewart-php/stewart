<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Reload\SourcePathFinder;
use Stewart\Runtime\Broker\Reload\SourcePaths;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(SourcePathFinder::class)]
#[CoversClass(SourcePaths::class)]
final class SourcePathFinderTest extends TestCase
{
    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-paths-');

        foreach (['apps', 'generated', 'tests'] as $directory) {
            mkdir($this->project->getFilePath($directory));
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAutoloadRootsAreWatchedButNotTheTests(): void
    {
        file_put_contents($this->project->getFilePath('composer.json'), json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'apps/', 'App\\Generated\\' => ['generated/'], 'Gone\\' => 'missing/']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
        ], \JSON_THROW_ON_ERROR));

        $paths = $this->findSourcePaths();

        self::assertSame([
            $this->project->getFilePath('apps'),
            $this->project->getFilePath('generated'),
            $this->project->getFilePath('services.php'),
            $this->project->getFilePath('stewart.yaml'),
        ], $paths->reloading);
        self::assertSame([$this->project->getFilePath('.env'), $this->project->getFilePath('composer.lock')], $paths->restartOnly);
    }

    public function testConfigFileOptionIsWatched(): void
    {
        $paths = $this->findSourcePaths(new DaemonLaunchOptions('/etc/stewart/custom.yaml'));

        self::assertSame([$this->project->getFilePath('services.php'), '/etc/stewart/custom.yaml'], $paths->reloading);
    }

    private function findSourcePaths(DaemonLaunchOptions $launch = new DaemonLaunchOptions()): SourcePaths
    {
        $root = new ProjectRoot($this->project->path);

        return new SourcePathFinder($root, ConfigLoaderFixture::createLoader(new EnvironmentVariables(), $root), $launch, $this->project->getFilePath('services.php'))->findSourcePaths();
    }
}
