<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\Console\CheckCommand;
use Stewart\Runtime\Tests\Fixtures\Apps\TemporaryAppsDirectory;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
{
    private const string VALID_CONFIG = "home_assistant:\n  url: 'ws://example.invalid/api/websocket'\n  token: check-token\n";

    private const string LOADABLE_APP_SOURCE = <<<'PHP'
        use Stewart\Contracts\App;
        use Stewart\Contracts\Automation;

        #[Automation(id: 'porch')]
        final class Porch implements App
        {
            public function initialize(): void {}

            public function dispose(): void {}
        }
        PHP;

    private TempDirectory $temp;

    private TemporaryAppsDirectory $apps;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-check-');
        $this->apps = new TemporaryAppsDirectory();
    }

    protected function tearDown(): void
    {
        $this->apps->remove();
        $this->temp->remove();
    }

    public function testProjectThatLoadsPasses(): void
    {
        $this->apps->writeClass('Porch', self::LOADABLE_APP_SOURCE);

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('1 automations load; 1 enabled', $tester->getDisplay());
    }

    public function testUnloadableAppFileFailsWithItsPath(): void
    {
        $this->apps->writeClass('Porch', self::LOADABLE_APP_SOURCE);
        $this->apps->writeClass('Broken', 'final class Broken {');

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Broken.php failed to load', $tester->getErrorOutput());
    }

    public function testMissingHomeAssistantSectionFails(): void
    {
        $tester = $this->runCheck("log_level: info\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('home_assistant', $tester->getErrorOutput());
    }

    public function testOverrideForUnknownAppFails(): void
    {
        $this->apps->writeClass('Porch', self::LOADABLE_APP_SOURCE);

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  missing:\n    enabled: false\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    private function runCheck(string $configYaml): CommandTester
    {
        $path = $this->temp->getFilePath('stewart.yaml');
        file_put_contents($path, $configYaml);

        $tester = new CommandTester(new CheckCommand(ConfigLoaderFixture::createLoader(), $this->apps->createDiscovery(), new AppCatalogResolver()));
        $tester->execute(['--config' => $path], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
