<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ConfigLoader::class)]
final class ConsoleConfigLoadTest extends TestCase
{
    private const string CONNECTION = "home_assistant:\n  url: ws://ha.local:8123\n  token: from-the-file\n";

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-config-load-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testWholePlaceholderFillsANumber(): void
    {
        $tester = $this->dumpConfig(self::CONNECTION . 'workers: ${POOL:-2}' . "\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^workers: 2$/m', $tester->getDisplay());
    }

    public function testMisspelledUrlSchemeFailsWithItsPath(): void
    {
        $tester = $this->dumpConfig("home_assistant:\n  url: htp://ha.local\n  token: from-the-file\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('home_assistant.url is invalid', $tester->getDisplay());
    }

    public function testSupervisorWebsocketPathIsKept(): void
    {
        $tester = $this->dumpConfig("home_assistant:\n  url: http://supervisor/core/websocket\n  token: from-the-file\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('ws://supervisor/core/websocket', $tester->getDisplay());
        self::assertStringNotContainsString('/api/websocket', $tester->getDisplay());
    }

    public function testKubernetesServiceLinksAreIgnored(): void
    {
        $tester = $this->dumpConfig(self::CONNECTION, ['STEWART_SERVICE_HOST' => '10.0.0.7', 'STEWART_PORT_8080_TCP' => 'tcp://10.0.0.7:8080']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /** @param array<string, string> $environment */
    private function dumpConfig(string $yaml, array $environment = []): CommandTester
    {
        $path = $this->temp->getFilePath('stewart.yaml');
        file_put_contents($path, $yaml);

        $tester = ConfigLoaderFixture::createConsoleConfigDump($this->temp->path, new EnvironmentVariables($environment));
        $tester->execute(['--config' => $path]);

        return $tester;
    }
}
