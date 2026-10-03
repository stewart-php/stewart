<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(StewartConfigSchema::class)]
final class StewartConfigSchemaTest extends TestCase
{
    public function testDefaultsFillInEverythingButTheConnection(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection()]);

        self::assertSame(0, self::getValueAt($processed, 'workers'));
        self::assertSame('info', self::getValueAt($processed, 'log_level'));
        self::assertSame('line', self::getValueAt($processed, 'log_format'));
        self::assertSame('5s', self::getValueAt($processed, 'shutdown_grace'));
        self::assertSame(1000, self::getValueAt($processed, 'worker_event_buffer'));
        self::assertSame(100, self::getValueAt($processed, 'subscription_buffer'));
        self::assertSame('10s', self::getValueAt($processed, 'home_assistant.heartbeat_interval'));
        self::assertSame(3, self::getValueAt($processed, 'home_assistant.heartbeat_missed_limit'));
        self::assertSame([], self::getValueAt($processed, 'apps'));

        self::assertSame('1s', self::getValueAt($processed, 'reconnect.initial_delay'));
        self::assertSame('60s', self::getValueAt($processed, 'reconnect.max_delay'));

        self::assertSame(5, self::getValueAt($processed, 'supervision.restart_attempts'));
        self::assertSame('60s', self::getValueAt($processed, 'supervision.restart_window'));
        self::assertSame('1s', self::getValueAt($processed, 'supervision.restart_initial_delay'));
        self::assertSame('30s', self::getValueAt($processed, 'supervision.restart_max_delay'));
        self::assertSame('10s', self::getValueAt($processed, 'supervision.ping_interval'));
        self::assertSame('500ms', self::getValueAt($processed, 'supervision.lag_threshold'));
        self::assertSame(3, self::getValueAt($processed, 'supervision.unresponsive_after'));
        self::assertSame('60s', self::getValueAt($processed, 'supervision.initialize_timeout'));
        self::assertSame('5m', self::getValueAt($processed, 'supervision.ready_timeout'));

        self::assertNull(self::getValueAt($processed, 'persistence.url'));
        self::assertSame('stewart', self::getValueAt($processed, 'persistence.prefix'));
        self::assertSame('2s', self::getValueAt($processed, 'persistence.timeout'));
        self::assertSame('5s', self::getValueAt($processed, 'persistence.recovery_interval'));

        self::assertSame('unix://var/run/stewart.sock', self::getValueAt($processed, 'control.listen'));
        self::assertNull(self::getValueAt($processed, 'control.token'));
    }

    public function testPingIntervalCanBeSwitchedOff(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'supervision' => ['ping_interval' => 'off'],
        ])]);

        self::assertSame('off', self::getValueAt($processed, 'supervision.ping_interval'));
    }

    public function testOneSupervisionOverrideKeepsOtherDefaults(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'supervision' => ['restart_attempts' => 2],
        ])]);

        self::assertSame(2, self::getValueAt($processed, 'supervision.restart_attempts'));
        self::assertSame('500ms', self::getValueAt($processed, 'supervision.lag_threshold'));
    }

    public function testHyphenatedAppIdSurvivesNormalization(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'apps' => ['hall-light' => ['worker' => 0]],
        ])]);

        self::assertSame(['hall-light'], self::listKeysAt($processed, 'apps'));
    }

    public function testAppGetsItsDefaults(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'apps' => ['demo' => []],
        ])]);

        self::assertTrue(self::getValueAt($processed, 'apps.demo.enabled'));
        self::assertNull(self::getValueAt($processed, 'apps.demo.worker'));
        self::assertSame([], self::getValueAt($processed, 'apps.demo.options'));
    }

    public function testExplicitlyNullWorkerMeansUnpinned(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'apps' => ['demo' => ['worker' => null]],
        ])]);

        self::assertNull(self::getValueAt($processed, 'apps.demo.worker'));
    }

    public function testUrlIsNormalizedAsItIsRead(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection(['home_assistant' => [
            'url' => 'http://ha.local:8123',
            'token' => 'secret',
        ]])]);

        self::assertSame('ws://ha.local:8123/api/websocket', self::getValueAt($processed, 'home_assistant.url'));
    }

    public function testUnusableUrlIsLeftForTheLoader(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection(['home_assistant' => [
            'url' => 'htp://ha.local',
            'token' => 'secret',
        ]])]);

        self::assertSame('htp://ha.local', self::getValueAt($processed, 'home_assistant.url'));
    }

    public function testOptionKeysAreNotNormalized(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'apps' => ['demo' => [
                'options' => ['watch' => 'light.hall', 'max-retries' => 3],
            ]],
        ])]);

        self::assertSame(['watch' => 'light.hall', 'max-retries' => 3], self::getValueAt($processed, 'apps.demo.options'));
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('provideRejections')]
    public function testInvalidConfigurationIsRejected(array $config, string $expected): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches($expected);

        self::processConfigs([self::addRequiredConnection($config)]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideRejections(): iterable
    {
        yield 'misspelled top-level key' => [
            ['worker_evnt_buffer' => 5000],
            '/Unrecognized option "worker_evnt_buffer".*worker_event_buffer/s',
        ];

        yield 'misspelled app key' => [
            ['apps' => ['demo' => ['workers' => 1]]],
            '/Unrecognized option "workers".*worker/s',
        ];

        yield 'unknown log level' => [
            ['log_level' => 'chatty'],
            '/log_level/',
        ];

        yield 'quoted number where an int is wanted' => [
            ['worker_event_buffer' => '1000'],
            '/worker_event_buffer/',
        ];

        yield 'negative worker count' => [
            ['workers' => -1],
            '/workers/',
        ];

        yield 'misspelled supervision key' => [
            ['supervision' => ['ping_intervall' => 5.0]],
            '/Unrecognized option "ping_intervall".*ping_interval/s',
        ];

        yield 'a class named where none belongs' => [
            ['apps' => ['demo' => ['class' => Demo::class]]],
            '/Unrecognized option "class"/',
        ];

        yield 'an empty entity selector' => [
            ['codegen' => ['include' => ['']]],
            '/stewart\.codegen\.include\.0" cannot contain an empty value/',
        ];

        yield 'an attribute domain that is no domain' => [
            ['codegen' => ['attributes' => ['Sensor!' => []]]],
            '/path "stewart\.codegen\.attributes": "Sensor!" is not a domain/',
        ];

        yield 'an app key that cannot be an automation ID' => [
            ['apps' => ['Hall' => []]],
            '/path "stewart\.apps": "Hall" is not an automation ID/',
        ];
    }

    public function testConnectionIsOptional(): void
    {
        self::assertArrayNotHasKey('home_assistant', self::processConfigs([['workers' => 1]]));
    }

    public function testConnectionThatIsGivenNeedsItsTokenAndUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"token" under "stewart\.home_assistant" must be configured/');

        self::processConfigs([['home_assistant' => ['url' => 'ws://ha.local:8123']]]);
    }

    public function testControlListenAcceptsOffInAnyCase(): void
    {
        self::assertSame('OFF', self::getValueAt(self::processConfigs([self::addRequiredConnection(['control' => ['listen' => 'OFF']])]), 'control.listen'));
    }

    public function testLaterListReplacesEarlierInsteadOfAppending(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection(['codegen' => ['include' => ['light.*']]]), ['codegen' => ['include' => ['switch.*']]]]);

        self::assertSame(['switch.*'], self::getValueAt($processed, 'codegen.include'));
    }

    public function testCodegenDefaultsToEverythingUnderGenerated(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection()]);

        self::assertSame('App\\Generated', self::getValueAt($processed, 'codegen.namespace'));
        self::assertSame('generated', self::getValueAt($processed, 'codegen.output_dir'));
        self::assertSame(['*'], self::getValueAt($processed, 'codegen.include'));
        self::assertSame([], self::getValueAt($processed, 'codegen.exclude'));
        self::assertSame([], self::getValueAt($processed, 'codegen.attributes'));
    }

    public function testAttributeDomainsKeepKeysAndFillDefaults(): void
    {
        $processed = self::processConfigs([self::addRequiredConnection([
            'codegen' => ['attributes' => ['sensor' => ['exclude' => ['*_alarm']], 'vacuum' => ['include' => ['mop_*']]]],
        ])]);

        self::assertSame(
            [
                'sensor' => ['exclude' => ['*_alarm'], 'include' => []],
                'vacuum' => ['include' => ['mop_*'], 'exclude' => []],
            ],
            self::getValueAt($processed, 'codegen.attributes'),
        );
    }

    public function testFormerAttributeThresholdIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/min_entities/');

        self::processConfigs([self::addRequiredConnection(['codegen' => ['attributes' => ['sensor' => ['min_entities' => 1]]]])]);
    }

    public function testFormerRenameIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "rename"/');

        self::processConfigs([self::addRequiredConnection(['codegen' => ['rename' => ['light.hall' => 'hall']]])]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function addRequiredConnection(array $config = []): array
    {
        return $config + ['home_assistant' => ['url' => 'ws://ha.local:8123/api/websocket', 'token' => 'secret']];
    }

    /**
     * @param list<array<string, mixed>> $configs
     * @return array<array-key, mixed>
     */
    private static function processConfigs(array $configs): array
    {
        /** @var array<array-key, mixed> $processed */
        $processed = new Processor()->processConfiguration(new StewartConfigSchema(), $configs);

        return $processed;
    }

    /** @param array<array-key, mixed> $values */
    private static function getValueAt(array $values, string $path): mixed
    {
        $cursor = $values;

        foreach (explode('.', $path) as $key) {
            if (!\is_array($cursor) || !\array_key_exists($key, $cursor)) {
                self::fail(\sprintf('Nothing at "%s" in the processed configuration.', $path));
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function listKeysAt(array $values, string $path): array
    {
        $section = self::getValueAt($values, $path);

        if (!\is_array($section)) {
            self::fail(\sprintf('"%s" is not a section.', $path));
        }

        return array_map(static fn(int|string $key): string => (string) $key, array_keys($section));
    }
}
