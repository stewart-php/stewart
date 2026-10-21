<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\Reload\ReloadCheckApp;
use Stewart\Runtime\Broker\Reload\ReloadCheckReport;
use Stewart\Runtime\Broker\Reload\ReloadCheckReportCodec;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Console\Check\ProjectCheck;
use Stewart\Runtime\Console\ReloadCheckCommand;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Apps\TemporaryAppsDirectory;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReloadCheckCommand::class)]
#[CoversClass(ProjectCheck::class)]
final class ReloadCheckCommandTest extends TestCase
{
    private const string CONFIG = "home_assistant:\n  url: 'ws://example.invalid/api/websocket'\n  token: check-token\n";

    private TempDirectory $project;

    private TemporaryAppsDirectory $apps;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-reload-check-');
        $this->apps = new TemporaryAppsDirectory();
    }

    protected function tearDown(): void
    {
        $this->apps->remove();
        $this->project->remove();
    }

    public function testPassingProjectReportsItsAppsAndConfigVersion(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch', 'public string $secret'));
        $config = $this->writeConfig(self::CONFIG . "apps:\n  porch:\n    options:\n      secret: hunter2\n");

        [$status, $report, $output] = $this->runReloadCheck(['--config' => $config]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([['porch', $this->apps->namespace . '\\Porch']], array_map(static fn(ReloadCheckApp $app): array => [$app->id, $app->class], $report->apps));
        self::assertSame(hash_file('xxh128', $config), $report->configFingerprint);
        self::assertStringNotContainsString('hunter2', $output, 'Option values never leave the check.');
    }

    public function testFailingProjectReportsItsErrors(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch', 'public string $entity'));

        [$status, $report] = $this->runReloadCheck(['--config' => $this->writeConfig(self::CONFIG)]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringStartsWith('App "porch" needs option "entity";', (string) $report->findFirstError());
    }

    public function testOnlyChecksTheSelectedApps(): void
    {
        $this->apps->writeClass('Hall', self::createAppSource('Hall', 'hall', 'public string $entity'));
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        [$status] = $this->runReloadCheck(['--config' => $this->writeConfig(self::CONFIG), '--only' => ['porch']]);

        self::assertSame(Command::SUCCESS, $status);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, ReloadCheckReport, string}
     */
    private function runReloadCheck(array $input): array
    {
        $services = new SyntheticServices()
            ->withService(EnvironmentVariables::class, new EnvironmentVariables([]))
            ->withService(AppDiscovery::class, $this->apps->createDiscovery())
            ->withService(AppPlacement::class, new AppPlacement(2));
        $tester = new CommandTester(new ConsoleKernel($this->project->path, [], $services)->createApplication()->find(ReloadCheckCommand::NAME));
        $status = $tester->execute($input);
        $output = $tester->getDisplay();

        return [$status, new ReloadCheckReportCodec(ReloadCheckReportCodec::createReportWireMapper())->decodeReport(trim($output)), $output];
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->project->getFilePath('stewart.yaml');
        file_put_contents($path, $yaml);

        return $path;
    }

    private static function createAppSource(string $class, string $id, string $constructorParameters = ''): string
    {
        $constructor = $constructorParameters === '' ? '' : "public function __construct({$constructorParameters}) {}\n\n    ";

        return <<<PHP
            use Stewart\\Contracts\\App;
            use Stewart\\Contracts\\Automation;

            #[Automation(id: '{$id}')]
            final class {$class} implements App
            {
                {$constructor}public function initialize(): void {}
            }
            PHP;
    }
}
