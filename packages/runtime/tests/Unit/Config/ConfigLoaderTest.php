<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\AppOverride;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Config\ConfigFileReader;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ConfigSection;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Config\DomainAttributesConfig;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\OptionalDuration;
use Stewart\Runtime\Config\PersistenceConfig;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(ConfigFileReader::class)]
#[CoversClass(StewartConfig::class)]
#[CoversClass(AppOverride::class)]
#[CoversClass(ConfigSection::class)]
#[CoversClass(OptionalDuration::class)]
#[CoversClass(BackoffPolicy::class)]
#[CoversClass(SupervisionConfig::class)]
#[CoversClass(ServiceCallPolicy::class)]
#[CoversClass(PersistenceConfig::class)]
#[CoversClass(ControlConfig::class)]
#[CoversClass(CodegenConfig::class)]
#[CoversClass(DomainAttributesConfig::class)]
final class ConfigLoaderTest extends TestCase
{
    use AssertsReason;

    private const string CONNECTION = <<<'YAML'
        home_assistant:
          url: ws://ha.local:8123/api/websocket
          token: from-the-file
        YAML;

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-config-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testFileIsReadIntoTypedObjects(): void
    {
        $config = $this->loadConfig(self::CONNECTION . <<<'YAML'

            workers: 2
            log_level: notice
            shutdown_grace: 2500ms

            apps:
              demo:
                worker: 0
                paused: true
                options:
                  watch: light.hall
              echo:
                enabled: false
            YAML);

        self::assertSame(2, $config->workers);
        self::assertSame(LogLevel::Notice, $config->logLevel);
        self::assertEquals(Duration::milliseconds(2_500), $config->shutdownGrace);
        self::assertSame('from-the-file', $config->requireHomeAssistant()->token);
        self::assertEquals(
            AppOverrideCollection::keyedByAppId([
                new AppOverride(new AppId('demo'), enabled: true, paused: true, worker: 0, options: ['watch' => 'light.hall']),
                new AppOverride(new AppId('echo'), enabled: false, paused: false, worker: null, options: []),
            ]),
            $config->apps,
        );
    }

    public function testSupervisionAndReconnectSections(): void
    {
        $config = $this->loadConfig(self::CONNECTION . <<<'YAML'

            reconnect:
              initial_delay: 500ms
              max_delay: 20s

            supervision:
              restart_attempts: 3
              ping_interval: off
              ready_timeout: off
              lag_threshold: 250ms
            YAML);

        self::assertEquals(new BackoffPolicy(Duration::milliseconds(500), Duration::seconds(20)), $config->reconnectBackoff);
        self::assertNull($config->supervision->pingInterval->findDuration());
        self::assertNull($config->supervision->readyTimeout->findDuration());
        self::assertEquals(Duration::milliseconds(250), $config->supervision->lagThreshold);
        self::assertEquals(Duration::minutes(1), $config->supervision->initializeTimeout->findDuration(), 'Untouched keys keep their defaults.');

        self::assertSame(3, $config->supervision->restartAttempts);
        self::assertEquals(new BackoffPolicy(Duration::seconds(1), Duration::seconds(30)), $config->supervision->restartBackoff);
    }

    public function testBareNumberDurationIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::KeyParseFailed, fn() => $this->loadConfig(self::CONNECTION . "\nshutdown_grace: 4\n"));

        self::assertMatchesRegularExpression('/^shutdown_grace is invalid/', $e->getMessage());
    }

    public function testOffOnlyWhereDurationCanBeOff(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::KeyParseFailed, fn() => $this->loadConfig(self::CONNECTION . "\nsupervision:\n  restart_window: off\n"));

        self::assertMatchesRegularExpression('/^supervision\.restart_window is invalid/', $e->getMessage());
    }

    public function testTimeoutThatCouldNeverBeMetIsRefused(): void
    {
        $this->assertThrowsReason(ConfigurationError::DurationTooShort, fn() => $this->loadConfig(self::CONNECTION, ['STEWART_HOME_ASSISTANT__COMMAND_TIMEOUT' => '0s']));
    }

    public function testDurationCanComeFromTheEnvironment(): void
    {
        $config = $this->loadConfig(self::CONNECTION, ['STEWART_SHUTDOWN_GRACE' => '2m', 'STEWART_SUPERVISION__PING_INTERVAL' => 'off']);

        self::assertEquals(Duration::minutes(2), $config->shutdownGrace);
        self::assertNull($config->supervision->pingInterval->findDuration());
    }

    public function testOffIsReadInAnyCase(): void
    {
        $config = $this->loadConfig(self::CONNECTION, ['STEWART_SUPERVISION__PING_INTERVAL' => 'OFF']);

        self::assertNull($config->supervision->pingInterval->findDuration());
    }

    public function testPlaceholderInTheFileReadsTheEnvironment(): void
    {
        $config = $this->loadConfig(
            "home_assistant:\n  url: ws://\${HA_HOST}:8123\n  token: \${HA_TOKEN}\nlog_level: \${LEVEL:-warning}\n",
            ['HA_HOST' => 'ha.local', 'HA_TOKEN' => 'from-a-placeholder'],
        );

        self::assertSame('ws://ha.local:8123/api/websocket', $config->requireHomeAssistant()->url->reveal());
        self::assertSame('from-a-placeholder', $config->requireHomeAssistant()->token);
        self::assertSame(LogLevel::Warning, $config->logLevel);
    }

    public function testUnsetPlaceholderStopsTheLoad(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::PlaceholderUnset, fn() => $this->loadConfig("home_assistant:\n  url: ws://ha.local\n  token: \${HA_TOKEN}\n"));

        self::assertStringContainsString('home_assistant.token refers to ${HA_TOKEN}', $e->getMessage());
    }

    public function testPrecedenceIsFileThenEnvThenCommandLine(): void
    {
        $yaml = self::CONNECTION . "\nlog_level: \${LEVEL}\nworkers: 2\n";

        self::assertSame(LogLevel::Notice, $this->loadConfig($yaml, ['LEVEL' => 'notice'])->logLevel);
        self::assertSame(LogLevel::Debug, $this->loadConfig($yaml, ['LEVEL' => 'notice', 'STEWART_LOG_LEVEL' => 'debug'])->logLevel);
        self::assertSame(LogLevel::Error, $this->loadConfig($yaml, ['LEVEL' => 'notice', 'STEWART_LOG_LEVEL' => 'debug'], ['log_level' => 'error'])->logLevel);
        self::assertSame(2, $this->loadConfig($yaml, ['LEVEL' => 'notice'])->workers);
    }

    public function testLogFormatComesFromEnvironment(): void
    {
        self::assertSame(LogFormat::Line, $this->loadConfig(self::CONNECTION)->logFormat);
        self::assertSame(LogFormat::Json, $this->loadConfig(self::CONNECTION, ['STEWART_LOG_FORMAT' => 'json'])->logFormat);
    }

    public function testOverridingOneOptionLeavesItsSiblingsAlone(): void
    {
        $config = $this->loadConfig(
            self::CONNECTION . <<<'YAML'

                apps:
                  demo:
                    options:
                      watch: light.hall
                      light: light.kitchen
                YAML,
            ['STEWART_APPS__DEMO__OPTIONS__WATCH' => 'light.porch'],
        );

        self::assertSame(['watch' => 'light.porch', 'light' => 'light.kitchen'], $config->apps->find(new AppId('demo'))?->options);
    }

    public function testEnvironmentOptionListReplacesFileList(): void
    {
        $config = $this->loadConfig(
            self::CONNECTION . "\napps:\n  demo:\n    options:\n      rooms: [porch]\n",
            ['STEWART_APPS__DEMO__OPTIONS__ROOMS' => '[hall, kitchen]'],
        );

        self::assertSame(['rooms' => ['hall', 'kitchen']], $config->apps->find(new AppId('demo'))?->options);
    }

    public function testHyphenatedAppIsReachedThroughItsFileEntry(): void
    {
        $config = $this->loadConfig(self::CONNECTION . "\napps:\n  hall-light: ~\n", ['STEWART_APPS__HALL_LIGHT__WORKER' => '2']);

        self::assertSame(2, $config->apps->find(new AppId('hall-light'))?->worker);
    }

    public function testLoaderDoesNotLookForAutomations(): void
    {
        $config = $this->loadConfig(self::CONNECTION . "\napps:\n  nonesuch:\n    worker: 0\n");

        self::assertSame(['nonesuch'], $config->apps->mapToList(static fn(AppOverride $app): string => $app->id->value), 'Whether nonesuch exists is decided when the daemon starts.');
    }

    public function testAppKeyThatCannotBeAnIdIsRefusedWithItsPath(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::SchemaViolation, fn() => $this->loadConfig(self::CONNECTION . "\napps:\n  123:\n    enabled: false\n"));

        self::assertMatchesRegularExpression('/"stewart\.apps": "123" is not an automation ID/', $e->getMessage());
    }

    public function testUrlIsNormalizedWhereverItComesFrom(): void
    {
        $config = $this->loadConfig("home_assistant:\n  token: t\n", ['STEWART_HOME_ASSISTANT__URL' => 'https://ha.example.com']);

        self::assertSame('wss://ha.example.com/api/websocket', $config->requireHomeAssistant()->url->reveal());
    }

    public function testConnectionKeysReachTheirObject(): void
    {
        $config = $this->loadConfig(self::CONNECTION . <<<'YAML'

              heartbeat_interval: off
              heartbeat_missed_limit: 5

            subscription_buffer: 25

            supervision:
              unresponsive_after: 0
            YAML);

        self::assertNull($config->requireHomeAssistant()->heartbeatInterval);
        self::assertSame(5, $config->requireHomeAssistant()->heartbeatMissedLimit);
        self::assertSame(25, $config->subscriptionBuffer);
        self::assertFalse($config->supervision->killsUnresponsiveWorkers());
    }

    public function testFileWithoutAConnectionLoadsButCannotConnect(): void
    {
        $config = $this->loadConfig("workers: 1\n");

        self::assertNull($config->homeAssistant);

        $this->assertThrowsReason(ConfigurationError::HomeAssistantMissing, fn() => $config->requireHomeAssistant());
    }

    public function testMissingFileSaysSo(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ConfigFileMissing, fn() => ConfigLoaderFixture::createLoader()->loadConfig($this->temp->getFilePath('absent.yaml')));

        self::assertMatchesRegularExpression('/not found/', $e->getMessage());
    }

    public function testDefaultConfigIsFoundInProjectRoot(): void
    {
        file_put_contents($this->temp->getFilePath('stewart.yaml'), self::CONNECTION . "\nworkers: 3\n");

        $config = ConfigLoaderFixture::createLoader(projectRoot: new ProjectRoot($this->temp->path))->loadConfig(null);

        self::assertSame(3, $config->workers);
    }

    public function testUnparseableYamlNamesTheFile(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ConfigFileUnparsable, fn() => $this->loadConfig("apps: [unclosed\n"));

        self::assertMatchesRegularExpression('/Could not parse/', $e->getMessage());
    }

    public function testWithoutAUrlThereIsNoPersistence(): void
    {
        self::assertNull($this->loadConfig(self::CONNECTION)->persistence);
        self::assertNull($this->loadConfig(self::CONNECTION . "\npersistence:\n  url: ''\n")->persistence);
    }

    public function testStorageUrlIsParsedIntoATypedSetting(): void
    {
        $persistence = $this->loadConfig(self::CONNECTION . <<<'YAML'

            persistence:
              url: redis://valkey:6379/3
              prefix: upstairs
              timeout: 500ms
            YAML)->persistence;

        self::assertNotNull($persistence);
        self::assertSame('redis', $persistence->url->scheme);
        self::assertSame('redis://valkey:6379/3', $persistence->url->reveal());
        self::assertSame('upstairs', $persistence->prefix->value);
        self::assertSame(500, $persistence->timeout->toMilliseconds());
        self::assertSame(5000, $persistence->recoveryInterval->toMilliseconds());
    }

    public function testStorageUrlCanComeFromTheEnvironment(): void
    {
        $persistence = $this->loadConfig(self::CONNECTION, ['STEWART_PERSISTENCE__URL' => 'redis://elsewhere:6379'])->persistence;

        self::assertNotNull($persistence);
        self::assertSame('redis://elsewhere:6379', $persistence->url->reveal());
    }

    public function testInvalidUrlIsNotEchoed(): void
    {
        try {
            $this->loadConfig(self::CONNECTION . "\npersistence:\n  url: hunter2\n");
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('persistence.url', $e->getMessage());
            self::assertStringContainsString('expected a URL like', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());

            return;
        }

        self::fail('That is not a URL.');
    }

    public function testControlSocketDefaultsOnWithoutToken(): void
    {
        $control = $this->loadConfig(self::CONNECTION)->control;

        self::assertInstanceOf(UnixControlAddress::class, $control->listen);
        self::assertSame('var/run/stewart.sock', $control->listen->path);
        self::assertNull($control->token, 'A token is the daemon\'s question at boot, not the loader\'s.');
    }

    public function testControlSectionBecomesItsOwnObject(): void
    {
        $control = $this->loadConfig(self::CONNECTION . "\ncontrol:\n  listen: tcp://127.0.0.1:7333\n  token: from-the-file\n")->control;

        self::assertSame('tcp://127.0.0.1:7333', (string) $control->listen);
        self::assertSame('from-the-file', $control->token);
    }

    public function testEmptyEnvironmentVariableMeansUnset(): void
    {
        self::assertNotNull($this->loadConfig(self::CONNECTION, ['STEWART_CONTROL__LISTEN' => ''])->control->listen);
        self::assertNull($this->loadConfig(self::CONNECTION, ['STEWART_CONTROL__LISTEN' => 'off'])->control->listen);
        self::assertNull($this->loadConfig(self::CONNECTION, ['STEWART_CONTROL__LISTEN' => 'Off'])->control->listen);
    }

    public function testControlTokenComesFromTheEnvironment(): void
    {
        self::assertSame('from-the-environment', $this->loadConfig(self::CONNECTION, ['STEWART_CONTROL__TOKEN' => 'from-the-environment'])->control->token);
    }

    public function testMalformedControlAddressIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::KeyParseFailed, fn() => $this->loadConfig(self::CONNECTION . "\ncontrol:\n  listen: http://localhost:8080\n"));

        self::assertStringContainsString('control.listen is invalid: "http://localhost:8080" is not a control address; use unix://path, tcp://host:port or "off".', $e->getMessage());
    }

    public function testCodegenSectionBecomesItsOwnObject(): void
    {
        $codegen = $this->loadConfig(self::CONNECTION . <<<'YAML'

            codegen:
              namespace: Acme\Home
              output_dir: src/generated/
              include:
                - light.*
                - switch.*
              exclude:
                - light.debug_*
              attributes:
                sensor:
                  include: [battery*]
            YAML)->codegen;

        self::assertSame('Acme\Home', $codegen->namespace->value);
        self::assertSame('src/generated', $codegen->outputDir->value);
        self::assertSame(['light.*', 'switch.*'], $codegen->include);
        self::assertSame(['light.debug_*'], $codegen->exclude);
        self::assertEquals(new DomainAttributesConfig('sensor', ['battery*'], []), $codegen->attributes->find('sensor'));
    }

    public function testEnvironmentCodegenListReplacesFileList(): void
    {
        $codegen = $this->loadConfig(self::CONNECTION . "\ncodegen:\n  include: [light.*]\n", ['STEWART_CODEGEN__INCLUDE' => '[switch.*]'])->codegen;

        self::assertSame(['switch.*'], $codegen->include);
    }

    public function testProcessReturnsTheValidatedTree(): void
    {
        $path = $this->temp->getFilePath('stewart.yaml');
        file_put_contents($path, self::CONNECTION);

        $processed = ConfigLoaderFixture::createLoader(new EnvironmentVariables(['STEWART_WORKERS' => '3']))->mergeConfigTree($path);

        self::assertSame(3, $processed['workers']);
        self::assertSame('from-the-file', ConfigSection::forRoot($processed)->readSection('home_assistant')->findString('token'));
        self::assertSame('5s', $processed['shutdown_grace']);
    }

    /**
     * @param array<string, string> $environment
     * @param array<string, mixed> $cliOverrides
     */
    private function loadConfig(string $yaml, array $environment = [], array $cliOverrides = []): StewartConfig
    {
        $path = $this->temp->getFilePath('stewart.yaml');
        file_put_contents($path, $yaml);

        return ConfigLoaderFixture::createLoader(new EnvironmentVariables($environment))->loadConfig($path, $cliOverrides);
    }
}
