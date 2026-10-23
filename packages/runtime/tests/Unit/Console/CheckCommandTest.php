<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Console\CheckCommand;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Apps\TemporaryAppsDirectory;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
{
    private const string VALID_CONFIG = "home_assistant:\n  url: 'ws://example.invalid/api/websocket'\n  token: check-token\n";

    private const string WITH_PERSISTENCE = "persistence:\n  url: 'redis://valkey.invalid:6379/0'\n";

    private const string UNWIRABLE_SERVICES_FILE = __DIR__ . '/../../Fixtures/Container/services/unwirable-service.php';

    private const int AUTO_POOL_SIZE = 2;

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
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('1 automations load; 1 enabled', $tester->getDisplay());
    }

    public function testEveryAppIsListedWithItsWorkerAndState(): void
    {
        $this->apps->writeClass('Garden', self::createAppSource('Garden', 'garden'));
        $this->apps->writeClass('Hall', self::createAppSource('Hall', 'hall'));
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  garden:\n    enabled: false\n  hall:\n    paused: true\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertMatchesRegularExpression('/^ *garden +\S+\\\\Garden +- +disabled *$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^ *hall +\S+\\\\Hall +1 +paused *$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^ *porch +\S+\\\\Porch +0 +enabled *$/m', $tester->getDisplay());
    }

    public function testPinnedAppKeepsItsWorker(): void
    {
        $this->apps->writeClass('Hall', self::createAppSource('Hall', 'hall'));
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  porch:\n    worker: 3\n");

        self::assertMatchesRegularExpression('/^ *hall +\S+ +1 +enabled *$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^ *porch +\S+ +3 +enabled *$/m', $tester->getDisplay());
    }

    public function testUnloadableAppFileFailsWithItsPath(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));
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

    public function testOverrideForUnknownAppWarnsAndPasses(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  missing:\n    enabled: false\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('were not found, so their settings are ignored: missing. Known automations: porch.', $tester->getErrorOutput());
        self::assertStringContainsString('1 automations load; 1 enabled', $tester->getDisplay());
    }

    public function testEnvironmentOverrideReachesHyphenatedApp(): void
    {
        $this->apps->writeClass('PorchLight', self::createAppSource('PorchLight', 'porch-light'));

        $tester = $this->runCheck(self::VALID_CONFIG, ['STEWART_APPS__PORCH_LIGHT__ENABLED' => 'false']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('1 automations load; 0 enabled', $tester->getDisplay());
    }

    public function testEnvironmentAloneIsEnoughWithoutAConfigFile(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->createTester(['STEWART_HOME_ASSISTANT__URL' => 'ws://ha.local:8123', 'STEWART_HOME_ASSISTANT__TOKEN' => 'check-token']);
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('No config file at ' . $this->temp->getFilePath('stewart.yaml') . '; using the environment only.', $tester->getErrorOutput());
    }

    public function testUnbuildableAppFailsBeforeAnyOutput(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch', 'public string $entity'));

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringStartsWith('App "porch" needs option "entity";', $tester->getErrorOutput());
    }

    public function testEveryUnbuildableAppIsNamedInIdOrder(): void
    {
        $this->apps->writeClass('Garden', self::createAppSource('Garden', 'garden'));
        $this->apps->writeClass('Hall', self::createAppSource('Hall', 'hall', 'public string $entity'));
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch', 'public \\Stewart\\Contracts\\Store\\Store $store'));

        $tester = $this->runCheck(self::VALID_CONFIG);
        $lines = explode("\n", trim($tester->getErrorOutput()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(2, $lines);
        self::assertStringStartsWith('App "hall" needs option "entity";', $lines[0]);
        self::assertStringStartsWith('App "porch" cannot be built: Persistence is not configured;', $lines[1]);
    }

    public function testStoreUserPassesWithPersistence(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch', 'public \\Stewart\\Contracts\\Store\\Store $store'));

        $tester = $this->runCheck(self::VALID_CONFIG . self::WITH_PERSISTENCE);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    public function testUnknownPeerStoreFailsWithASuggestion(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));
        $this->apps->writeClass('Keeper', self::createAppSource('Keeper', 'keeper', "#[\\Stewart\\Contracts\\Store\\PeerStore('porhc')] public \\Stewart\\Contracts\\Store\\ReadableStore \$peer"));

        $tester = $this->runCheck(self::VALID_CONFIG . self::WITH_PERSISTENCE);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('App "keeper" cannot be built:', $tester->getErrorOutput());
        self::assertStringContainsString('Did you mean "porch"?', $tester->getErrorOutput());
    }

    public function testBrokenServicesFileFailsOnce(): void
    {
        $this->apps->writeClass('Hall', self::createAppSource('Hall', 'hall'));
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));
        copy(self::UNWIRABLE_SERVICES_FILE, $this->temp->getFilePath('services.php'));

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, explode("\n", trim($tester->getErrorOutput())));
        self::assertStringStartsWith('Worker 0 failed to build its container:', $tester->getErrorOutput());
    }

    public function testUnmarkedAppClassIsNoticed(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));
        $this->apps->writeClass('Forgotten', "final class Forgotten implements \\Stewart\\Contracts\\App\n{\n    public function initialize(): void {}\n}");

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString($this->apps->namespace . '\\Forgotten implements App but is not marked #[Automation] or #[AppTemplate], so it will not run.', $tester->getErrorOutput());
    }

    public function testDisposeWithoutDisposableIsNoticed(): void
    {
        $this->apps->writeClass('Legacy', self::createAppSource('Legacy', 'legacy', body: 'public function dispose(): void {}'));
        $this->apps->writeClass('Tidy', self::createAppSource('Tidy', 'tidy', body: 'public function dispose(): void {}', interfaces: ', \\Stewart\\Contracts\\Disposable'));

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString($this->apps->namespace . '\\Legacy declares dispose() without implementing Disposable;', $tester->getErrorOutput());
        self::assertStringNotContainsString('Tidy declares', $tester->getErrorOutput());
    }

    public function testAdminPortWithoutTokenIsOnlyNoticed(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));

        $tester = $this->runCheck(self::VALID_CONFIG . "http:\n  admin:\n    listen: 'tcp://127.0.0.1:8081'\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('http.admin.token is required while http.admin.listen is set;', $tester->getErrorOutput());
        self::assertStringContainsString('The daemon refuses to start until it is fixed.', $tester->getErrorOutput());
    }

    public function testEveryEntryOfATemplateIsListedAsAnApp(): void
    {
        $this->apps->writeClass('RoomLight', "#[\\Stewart\\Contracts\\AppTemplate]\nfinal class RoomLight implements \\Stewart\\Contracts\\App\n{\n    public function __construct(public string \$light) {}\n\n    public function initialize(): void {}\n}");
        $class = $this->apps->namespace . '\\RoomLight';

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  hall-light:\n    class: {$class}\n    options:\n      light: light.hall\n  landing-light:\n    class: {$class}\n    options:\n      light: light.landing\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('2 automations load; 2 enabled', $tester->getDisplay());
        self::assertMatchesRegularExpression('/hall-light\s+' . preg_quote($class, '/') . '/', $tester->getDisplay());
    }

    public function testATemplateNoEntryNamesIsNoticed(): void
    {
        $this->apps->writeClass('Porch', self::createAppSource('Porch', 'porch'));
        $this->apps->writeClass('RoomLight', "#[\\Stewart\\Contracts\\AppTemplate]\nfinal class RoomLight implements \\Stewart\\Contracts\\App\n{\n    public function initialize(): void {}\n}");

        $tester = $this->runCheck(self::VALID_CONFIG);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString($this->apps->namespace . '\\RoomLight is an #[AppTemplate], but no apps.<id>.class names it', $tester->getErrorOutput());
    }

    public function testTheTableGroupsTheEntriesOfAClassAndNotesAnUnusedTemplate(): void
    {
        $this->apps->writeClass('Attic', self::createAppSource('Attic', 'attic'));
        $this->apps->writeClass('RoomLight', "#[\\Stewart\\Contracts\\AppTemplate]\nfinal class RoomLight implements \\Stewart\\Contracts\\App\n{\n    public function initialize(): void {}\n}");
        $this->apps->writeClass('Spare', "#[\\Stewart\\Contracts\\AppTemplate]\nfinal class Spare implements \\Stewart\\Contracts\\App\n{\n    public function initialize(): void {}\n}");
        $class = $this->apps->namespace . '\\RoomLight';

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  zz-hall:\n    class: {$class}\n  aa-landing:\n    class: {$class}\n");

        $rows = array_values(array_filter(array_map(trim(...), explode("\n", $tester->getDisplay())), static fn(string $line): bool => str_contains($line, 'Attic') || str_contains($line, 'RoomLight') || str_contains($line, 'Spare')));
        self::assertCount(4, $rows);
        self::assertMatchesRegularExpression('/^attic\s/', $rows[0]);
        self::assertMatchesRegularExpression('/^aa-landing\s/', $rows[1]);
        self::assertMatchesRegularExpression('/^zz-hall\s/', $rows[2]);
        self::assertMatchesRegularExpression('/^-\s+\S+Spare\s+-\s+no entry$/', $rows[3]);
    }

    public function testAMistypedClassFailsWithTheClosestName(): void
    {
        $this->apps->writeClass('RoomLight', "#[\\Stewart\\Contracts\\AppTemplate]\nfinal class RoomLight implements \\Stewart\\Contracts\\App\n{\n    public function initialize(): void {}\n}");

        $tester = $this->runCheck(self::VALID_CONFIG . "apps:\n  hall-light:\n    class: {$this->apps->namespace}\\RoomLite\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Did you mean ' . $this->apps->namespace . '\\RoomLight?', $tester->getErrorOutput());
    }

    private static function createAppSource(string $class, string $id, string $constructorParameters = '', string $body = '', string $interfaces = ''): string
    {
        $constructor = $constructorParameters === '' ? '' : "public function __construct({$constructorParameters}) {}\n\n    ";

        return <<<PHP
            use Stewart\\Contracts\\App;
            use Stewart\\Contracts\\Automation;

            #[Automation(id: '{$id}')]
            final class {$class} implements App{$interfaces}
            {
                {$constructor}public function initialize(): void {}

                {$body}
            }
            PHP;
    }

    /** @param array<string, string> $environment */
    private function runCheck(string $configYaml, array $environment = []): CommandTester
    {
        $path = $this->temp->getFilePath('stewart.yaml');
        file_put_contents($path, $configYaml);

        $tester = $this->createTester($environment);
        $tester->execute(['--config' => $path], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** @param array<string, string> $environment */
    private function createTester(array $environment): CommandTester
    {
        $services = new SyntheticServices()
            ->withService(EnvironmentVariables::class, new EnvironmentVariables($environment))
            ->withService(AppDiscovery::class, $this->apps->createDiscovery())
            ->withService(AppPlacement::class, new AppPlacement(self::AUTO_POOL_SIZE));

        return new CommandTester(new ConsoleKernel($this->temp->path, [], $services)->createApplication()->find(CheckCommand::NAME));
    }
}
