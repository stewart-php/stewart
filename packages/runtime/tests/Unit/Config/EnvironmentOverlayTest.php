<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\EnvironmentOverlay;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\SecretFileReader;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(EnvironmentOverlay::class)]
#[CoversClass(EnvironmentVariables::class)]
#[CoversClass(SecretFileReader::class)]
final class EnvironmentOverlayTest extends TestCase
{
    use AssertsReason;

    private TempDirectory $secrets;

    protected function setUp(): void
    {
        $this->secrets = TempDirectory::createWithPrefix('stewart-secrets-');
    }

    protected function tearDown(): void
    {
        $this->secrets->remove();
    }

    /**
     * @param array<string, string> $environment
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $file
     */
    #[DataProvider('provideMappings')]
    public function testVariableNamesAPath(array $environment, array $expected, array $file = []): void
    {
        self::assertSame($expected, self::apply($environment, $file));
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     array<array-key, mixed>,
     *     2?: array<array-key, mixed>,
     * }>
     */
    public static function provideMappings(): iterable
    {
        yield 'a single underscore stays part of the key' => [
            ['STEWART_LOG_LEVEL' => 'debug'],
            ['log_level' => 'debug'],
        ];

        yield 'a longer key with several underscores' => [
            ['STEWART_WORKER_EVENT_BUFFER' => '250'],
            ['worker_event_buffer' => 250],
        ];

        yield 'a double underscore nests' => [
            ['STEWART_HOME_ASSISTANT__URL' => 'ws://ha.local/api/websocket'],
            ['home_assistant' => ['url' => 'ws://ha.local/api/websocket']],
        ];

        yield 'kubernetes service links are skipped' => [
            [
                'STEWART_SERVICE_HOST' => '10.0.0.7',
                'STEWART_SERVICE_PORT' => '8080',
                'STEWART_SERVICE_PORT_HTTP' => '8080',
                'STEWART_PORT' => 'tcp://10.0.0.7:8080',
                'STEWART_PORT_8080_TCP' => 'tcp://10.0.0.7:8080',
                'STEWART_PORT_8080_TCP_PROTO' => 'tcp',
                'STEWART_PORT_8080_TCP_PORT' => '8080',
                'STEWART_PORT_8080_TCP_ADDR' => '10.0.0.7',
                'STEWART_WORKERS' => '2',
            ],
            ['workers' => 2],
        ];

        yield 'service links of other services are skipped' => [
            [
                'STEWART_VALKEY_SERVICE_HOST' => '10.0.0.8',
                'STEWART_VALKEY_SERVICE_PORT' => '6379',
                'STEWART_VALKEY_PORT' => 'tcp://10.0.0.8:6379',
                'STEWART_VALKEY_PORT_6379_TCP_ADDR' => '10.0.0.8',
                'STEWART_WORKERS' => '2',
            ],
            ['workers' => 2],
        ];

        yield 'variables for the image entrypoint are skipped' => [
            ['STEWART_BOOT_GIT_URL' => 'https://example.test/home.git', 'STEWART_BOOT_COMPOSER' => 'auto', 'STEWART_WORKERS' => '2'],
            ['workers' => 2],
        ];

        yield 'an option ending in _FILE stays an option' => [
            ['STEWART_APPS__DEMO__OPTIONS__CERT_FILE' => '/etc/hall.pem'],
            ['apps' => ['demo' => ['options' => ['cert_file' => '/etc/hall.pem']]]],
        ];

        yield 'a setting that starts like a service link' => [
            ['STEWART_SERVICE_CALLS__MAX_IN_FLIGHT' => '8'],
            ['service_calls' => ['max_in_flight' => 8]],
        ];

        yield 'three levels deep' => [
            ['STEWART_APPS__DEMO__WORKER' => '1'],
            ['apps' => ['demo' => ['worker' => 1]]],
        ];

        yield 'a hyphenated app id is matched through the file' => [
            ['STEWART_APPS__HALL_LIGHT__WORKER' => '2'],
            ['apps' => ['hall-light' => ['worker' => 2]]],
            ['apps' => ['hall-light' => ['worker' => 0]]],
        ];

        yield 'an app id the file does not name is lowercased' => [
            ['STEWART_APPS__HALL_LIGHT__WORKER' => '2'],
            ['apps' => ['hall_light' => ['worker' => 2]]],
        ];

        yield 'an option name keeps the case the file gave it' => [
            ['STEWART_APPS__DEMO__OPTIONS__MAXRETRIES' => '3'],
            ['apps' => ['demo' => ['options' => ['maxRetries' => 3]]]],
            ['apps' => ['demo' => ['options' => ['maxRetries' => 1]]]],
        ];

        yield 'an option takes an inline list whole' => [
            ['STEWART_APPS__DEMO__OPTIONS__ROOMS' => '[hall, kitchen]'],
            ['apps' => ['demo' => ['options' => ['rooms' => ['hall', 'kitchen']]]]],
            ['apps' => ['demo' => ['options' => ['rooms' => ['porch']]]]],
        ];

        yield 'a list setting takes an inline list' => [
            ['STEWART_CODEGEN__INCLUDE' => '[light.*, switch.*]'],
            ['codegen' => ['include' => ['light.*', 'switch.*']]],
        ];

        yield 'a prototype inside a section resolves through the file' => [
            ['STEWART_CODEGEN__ATTRIBUTES__SENSOR__INCLUDE' => '[unit_of_measurement]'],
            ['codegen' => ['attributes' => ['sensor' => ['include' => ['unit_of_measurement']]]]],
            ['codegen' => ['attributes' => ['sensor' => ['exclude' => ['icon']]]]],
        ];

        yield 'an option takes an inline map whole' => [
            ['STEWART_APPS__DEMO__OPTIONS__LIGHTS' => '{hall: light.hall, porch: light.porch}'],
            ['apps' => ['demo' => ['options' => ['lights' => ['hall' => 'light.hall', 'porch' => 'light.porch']]]]],
            ['apps' => ['demo' => ['options' => ['lights' => []]]]],
        ];

        yield 'variables without the prefix are not ours' => [
            ['PATH' => '/usr/bin', 'HOME' => '/root', 'STEWART_WORKERS' => '3'],
            ['workers' => 3],
        ];

        yield 'an empty value means unset, not empty' => [
            ['STEWART_LOG_LEVEL' => ''],
            [],
        ];

        yield 'two variables into one section' => [
            ['STEWART_HOME_ASSISTANT__URL' => 'ws://ha/api/websocket', 'STEWART_HOME_ASSISTANT__TOKEN' => 't'],
            ['home_assistant' => ['token' => 't', 'url' => 'ws://ha/api/websocket']],
        ];
    }

    public function testSecretFileFillsSettingWithoutLineBreak(): void
    {
        $path = $this->writeSecret("from-the-file\r\n");

        self::assertSame(
            ['home_assistant' => ['token' => 'from-the-file']],
            self::apply(['STEWART_HOME_ASSISTANT__TOKEN_FILE' => $path]),
        );
    }

    public function testSecretFileIsTypedLikeItsSetting(): void
    {
        self::assertSame(['workers' => 3], self::apply(['STEWART_WORKERS_FILE' => $this->writeSecret("3\n")]));
    }

    public function testSettingAndItsSecretFileConflict(): void
    {
        $environment = ['STEWART_HOME_ASSISTANT__TOKEN' => 'inline', 'STEWART_HOME_ASSISTANT__TOKEN_FILE' => $this->writeSecret('file')];

        $this->assertThrowsReason(ConfigurationError::EnvironmentVariableConflict, static fn() => self::apply($environment));
    }

    public function testUnreadableSecretFileIsRefused(): void
    {
        $environment = ['STEWART_HOME_ASSISTANT__TOKEN_FILE' => $this->secrets->getFilePath('missing')];

        $this->assertThrowsReason(ConfigurationError::EnvironmentSecretFileUnreadable, static fn() => self::apply($environment));
    }

    /**
     * @param array<string, string> $environment
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('provideCoercions')]
    public function testValueIsTypedByTheNodeItLandsOn(array $environment, array $expected): void
    {
        self::assertSame($expected, self::apply($environment));
    }

    /** @return iterable<string, array{array<string, string>, array<array-key, mixed>}> */
    public static function provideCoercions(): iterable
    {
        yield 'integer node' => [['STEWART_WORKERS' => '4'], ['workers' => 4]];
        yield 'a duration stays the text it was written as' => [['STEWART_SHUTDOWN_GRACE' => '2500ms'], ['shutdown_grace' => '2500ms']];
        yield 'enum node stays a string' => [['STEWART_LOG_LEVEL' => 'warning'], ['log_level' => 'warning']];

        yield 'boolean node, true' => [
            ['STEWART_APPS__DEMO__ENABLED' => 'yes'],
            ['apps' => ['demo' => ['enabled' => true]]],
        ];

        yield 'boolean node, false' => [
            ['STEWART_APPS__DEMO__ENABLED' => 'off'],
            ['apps' => ['demo' => ['enabled' => false]]],
        ];
    }

    #[DataProvider('provideInferences')]
    public function testFreeFormOptionIsInferred(string $raw, mixed $expected): void
    {
        self::assertSame(
            ['apps' => ['demo' => ['options' => ['value' => $expected]]]],
            self::apply(['STEWART_APPS__DEMO__OPTIONS__VALUE' => $raw]),
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideInferences(): iterable
    {
        yield 'a whole number' => ['180', 180];
        yield 'a decimal' => ['1.5', 1.5];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'null' => ['null', null];
        yield 'an entity id' => ['light.hall', 'light.hall'];
        yield 'a leading zero stays text' => ['007', '007'];
        yield 'an inline list' => ['[hall, kitchen]', ['hall', 'kitchen']];
        yield 'an inline map' => ['{a: 1}', ['a' => 1]];
        yield 'an unclosed list stays text' => ['[hall, kitchen', '[hall, kitchen'];
        yield 'a regex stays text' => ['[0-9]+', '[0-9]+'];
        yield 'a template stays text' => ["{{ states('sensor.hall') }}", "{{ states('sensor.hall') }}"];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('provideRejections')]
    public function testUnusableVariableIsRefused(array $environment, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches($expected);

        self::apply($environment);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideRejections(): iterable
    {
        yield 'a misspelled setting suggests the real one' => [
            ['STEWART_LOGLEVEL' => 'debug'],
            '/STEWART_LOGLEVEL is not a Stewart setting; run `stewart config:reference` for all options\. Did you mean STEWART_LOG_LEVEL\?/',
        ];

        yield 'a misspelled nested setting keeps the path' => [
            ['STEWART_HOME_ASSISTANT__TOKEM' => 'x'],
            '/Did you mean STEWART_HOME_ASSISTANT__TOKEN\?/',
        ];

        yield 'a setting that resembles nothing gets no guess' => [
            ['STEWART_QUUX' => 'x'],
            '/STEWART_QUUX is not a Stewart setting; run `stewart config:reference` for all options\.$/',
        ];

        yield 'a _FILE suffix on something that is not a setting' => [
            ['STEWART_QUUX_FILE' => '/run/secrets/quux'],
            '/STEWART_QUUX_FILE is not a Stewart setting/',
        ];

        yield 'a port name outside the service-link shape' => [
            ['STEWART_PORT_HTTP' => '8080'],
            '/STEWART_PORT_HTTP is not a Stewart setting/',
        ];

        yield 'a word where a number belongs names the variable' => [
            ['STEWART_WORKERS' => 'lots'],
            '/STEWART_WORKERS is "lots"; expected a whole number\./',
        ];

        yield 'a word where a boolean belongs' => [
            ['STEWART_APPS__DEMO__ENABLED' => 'perhaps'],
            '/STEWART_APPS__DEMO__ENABLED is "perhaps"; expected true or false/',
        ];

        yield 'a section cannot be set as one value' => [
            ['STEWART_HOME_ASSISTANT' => 'ws://ha'],
            '/STEWART_HOME_ASSISTANT names a section/',
        ];

        yield 'a path deeper than the schema goes' => [
            ['STEWART_LOG_LEVEL__NESTED' => 'x'],
            '/deeper than the configuration; log_level is a value/',
        ];

        yield 'an option is set whole, not below its own key' => [
            ['STEWART_APPS__DEMO__OPTIONS__SETTINGS__FIRST' => 'one'],
            '/deeper than the configuration; apps\.demo\.options\.settings is a value/',
        ];

        yield 'a list setting wants an inline list' => [
            ['STEWART_CODEGEN__INCLUDE' => 'light.*'],
            '/STEWART_CODEGEN__INCLUDE is "light\.\*"; expected an inline list such as \[a, b\]/',
        ];

        yield 'a list setting that does not parse' => [
            ['STEWART_CODEGEN__INCLUDE' => '[light.*, switch.*'],
            '/STEWART_CODEGEN__INCLUDE is not a valid inline list or map/',
        ];
    }

    /**
     * @param array<string, string> $environment
     * @param array<array-key, mixed> $file
     * @return array<array-key, mixed>
     */
    private static function apply(array $environment, array $file = []): array
    {
        return ConfigLoaderFixture::createOverlay(new EnvironmentVariables($environment))->collectEnvironmentOverrides(new StewartConfigSchema()->buildConfigTree(), $file);
    }

    private function writeSecret(string $contents): string
    {
        $path = $this->secrets->getFilePath('secret');
        file_put_contents($path, $contents);

        return $path;
    }
}
