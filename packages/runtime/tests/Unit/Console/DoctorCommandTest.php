<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\HaClientFactory;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Console\Doctor\ConnectionChecks;
use Stewart\Runtime\Console\Doctor\ContainerChecks;
use Stewart\Runtime\Console\DoctorCommand;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Store\InMemoryStoreBackendFactory;
use Stewart\Store\StoreBackendRegistry;
use Stewart\Testing\Filesystem\TempDirectory;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DoctorCommand::class)]
#[CoversClass(ContainerChecks::class)]
#[CoversClass(ConnectionChecks::class)]
final class DoctorCommandTest extends TestCase
{
    private const array HOME_ASSISTANT = [
        'STEWART_HOME_ASSISTANT__URL' => 'ws://ha.test:8123/api/websocket',
        'STEWART_HOME_ASSISTANT__TOKEN' => 'doctor-token',
    ];

    private const array IN_MEMORY_STORE = ['STEWART_PERSISTENCE__URL' => 'memory://doctor'];

    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-doctor-');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testOfflineChecksOnlyTheContainer(): void
    {
        $tester = $this->runDoctor([], ['--offline' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All good.', $tester->getDisplay());

        foreach (['pcntl', 'json', 'ctype', 'tmpdir', 'unix sock'] as $label) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($label, '/') . '\s+✓/m', $tester->getDisplay());
        }

        self::assertStringNotContainsString('config', $tester->getDisplay());
    }

    public function testUnsetConnectionsAreSkipped(): void
    {
        $tester = $this->runDoctor([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  \.env\s+! warn/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  connection\s+– skip  set STEWART_HOME_ASSISTANT__URL/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  store\s+– skip/m', $tester->getDisplay());
    }

    public function testAdministratorWithLocationIntegrationAndStorePasses(): void
    {
        file_put_contents($this->project->getFilePath('.env'), "\n");
        $socket = self::createHomeAssistant(isAdmin: true, withLocation: true, componentProtocol: ComponentProtocol::VERSION);

        $tester = $this->runDoctor([...self::HOME_ASSISTANT, ...self::IN_MEMORY_STORE], [], $socket);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('FAIL', $tester->getDisplay());
        self::assertStringNotContainsString('warn', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  connection\s+✓  Home Assistant 2026\.9\.0 at /m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  token\s+✓  belongs to Stewart, an administrator$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  integration\s+✓  version 1\.4\.0$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  store\s+✓  answers at memory:\/\/doctor$/m', $tester->getDisplay());
    }

    public function testTokenOfAnotherUserFails(): void
    {
        $socket = self::createHomeAssistant(isAdmin: false, withLocation: true);

        $tester = $this->runDoctor(self::HOME_ASSISTANT, [], $socket);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  token\s+✗ FAIL  belongs to Stewart, who is not an administrator/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  integration\s+– skip/m', $tester->getDisplay());
    }

    public function testMissingLocationAndIntegrationOnlyWarn(): void
    {
        $socket = self::createHomeAssistant(isAdmin: true, withLocation: false);

        $tester = $this->runDoctor(self::HOME_ASSISTANT, [], $socket);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  location\s+! warn  Home Assistant has none/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  integration\s+! warn  not installed/m', $tester->getDisplay());
    }

    public function testIntegrationOfAnotherProtocolFails(): void
    {
        $socket = self::createHomeAssistant(isAdmin: true, withLocation: true, componentProtocol: ComponentProtocol::VERSION + 1);

        $tester = $this->runDoctor(self::HOME_ASSISTANT, [], $socket);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  integration\s+✗ FAIL  version 1\.4\.0 speaks protocol/m', $tester->getDisplay());
    }

    public function testRejectedTokenFailsTheConnection(): void
    {
        $socket = new FakeWebsocketConnection();
        $socket->queueFrame(['type' => 'auth_required', 'ha_version' => '2026.9.0']);
        $socket->replyWhenSent('auth', ['type' => 'auth_invalid', 'message' => 'Invalid access token']);

        $tester = $this->runDoctor(self::HOME_ASSISTANT, [], $socket);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  connection\s+✗ FAIL  Home Assistant rejected the access token/m', $tester->getDisplay());
    }

    public function testStoreWithoutABackendFails(): void
    {
        $tester = $this->runDoctor(['STEWART_PERSISTENCE__URL' => 'redis://valkey:6379/0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  store\s+✗ FAIL  No installed store backend handles "redis:\/\/"/m', $tester->getDisplay());
    }

    public function testConfigThatDoesNotLoadFails(): void
    {
        $tester = $this->runDoctor(['STEWART_LOG_LEVEL' => 'loud']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^  config\s+✗ FAIL  /m', $tester->getDisplay());
        self::assertStringNotContainsString('connection', $tester->getDisplay());
    }

    private static function createHomeAssistant(bool $isAdmin, bool $withLocation, ?int $componentProtocol = null): FakeWebsocketConnection
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $socket->replyWhenSent('auth/current_user', ['type' => 'result', 'success' => true, 'result' => ['id' => 'stewart-user', 'name' => 'Stewart', 'is_admin' => $isAdmin]]);
        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => [
            'time_zone' => 'Europe/Budapest',
            'state' => 'RUNNING',
            ...($withLocation ? ['latitude' => 47.4979, 'longitude' => 19.0402] : []),
        ]]);
        $socket->replyWhenSent('stewart/version', $componentProtocol === null
            ? ['type' => 'result', 'success' => false, 'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.']]
            : ['type' => 'result', 'success' => true, 'result' => ['component_version' => '1.4.0', 'protocol' => $componentProtocol]]);

        return $socket;
    }

    /**
     * @param array<string, string> $environment
     * @param array<string, mixed> $options
     */
    private function runDoctor(array $environment, array $options = [], FakeWebsocketConnection ...$sockets): CommandTester
    {
        $services = new SyntheticServices()
            ->withService(EnvironmentVariables::class, new EnvironmentVariables($environment))
            ->withService(HaClientFactory::class, new HaClientFactory(new ManualTimers(), new FakeWebsocketConnector(...$sockets)))
            ->withService(StoreBackendRegistry::class, new StoreBackendRegistry([new InMemoryStoreBackendFactory()]));
        $tester = new CommandTester(new ConsoleKernel($this->project->path, [], $services)->createApplication()->find(DoctorCommand::NAME));
        $tester->execute($options);

        return $tester;
    }
}
