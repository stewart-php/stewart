<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Kernel;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Kernel\ConsoleLauncher;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(ConsoleLauncher::class)]
final class ConsoleLauncherTest extends TestCase
{
    private const string VARIABLE = 'STEWART_LAUNCHER_TEST_VALUE';

    private const string DOTENV_LOADED_NAMES = 'SYMFONY_DOTENV_VARS';

    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-launcher-');
    }

    protected function tearDown(): void
    {
        foreach ([self::VARIABLE, self::DOTENV_LOADED_NAMES] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        $this->project->remove();
    }

    public function testProjectDirIsRootPackageDirectory(): void
    {
        $launcher = ConsoleLauncher::createForInstalledProject(new ClassLoader());

        self::assertFileExists($launcher->projectDir . '/composer.json');
        self::assertFileExists($launcher->projectDir . '/vendor/autoload.php');
    }

    public function testEnvironmentFileReachesProcessEnvironment(): void
    {
        file_put_contents($this->project->getFilePath('.env'), self::VARIABLE . "=from-file\n");

        $this->createLauncher()->loadEnvironmentFile();

        self::assertSame('from-file', getenv(self::VARIABLE));
    }

    public function testProcessEnvironmentWinsOverEnvironmentFile(): void
    {
        $_SERVER[self::VARIABLE] = 'from-process';
        file_put_contents($this->project->getFilePath('.env'), self::VARIABLE . "=from-file\n");

        $this->createLauncher()->loadEnvironmentFile();

        self::assertFalse(getenv(self::VARIABLE));
    }

    public function testMissingEnvironmentFileIsSkipped(): void
    {
        $this->createLauncher()->loadEnvironmentFile();

        self::assertFalse(getenv(self::VARIABLE));
    }

    private function createLauncher(): ConsoleLauncher
    {
        return new ConsoleLauncher($this->project->path, new ClassLoader());
    }
}
