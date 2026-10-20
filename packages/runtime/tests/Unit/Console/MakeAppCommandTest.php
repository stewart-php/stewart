<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Console\MakeAppCommand;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeAppCommand::class)]
final class MakeAppCommandTest extends TestCase
{
    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-make-app-');
        mkdir($this->project->getFilePath('apps'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCreatesTheAppAndItsTest(): void
    {
        $tester = $this->runMakeApp(['name' => 'PorchLight']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('Created apps/PorchLight.php and tests/PorchLightTest.php.', $tester->getDisplay());
        self::assertStringContainsString('Its ID is porch-light; its options go under apps.porch-light in stewart.yaml.', $tester->getDisplay());
        self::assertStringContainsString("#[Automation(id: 'porch-light')]", (string) file_get_contents($this->project->getFilePath('apps/PorchLight.php')));
    }

    public function testRefusalIsReportedWithoutWritingAnything(): void
    {
        file_put_contents($this->project->getFilePath('apps/PorchLight.php'), "<?php\n");

        $tester = $this->runMakeApp(['name' => 'PorchLight']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('apps/PorchLight.php already exists.', $tester->getErrorOutput());
        self::assertFileDoesNotExist($this->project->getFilePath('tests/PorchLightTest.php'));
    }

    /** @param array<string, string> $input */
    private function runMakeApp(array $input): CommandTester
    {
        $psr4 = ['App\\' => [$this->project->getFilePath('apps')]];
        $services = new SyntheticServices()->withService(EnvironmentVariables::class, new EnvironmentVariables());
        $tester = new CommandTester(new ConsoleKernel($this->project->path, $psr4, $services)->createApplication()->find(MakeAppCommand::NAME));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }
}
