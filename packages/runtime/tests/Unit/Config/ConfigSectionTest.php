<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ConfigSection;
use Stewart\Runtime\Config\OptionalDuration;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ConfigSection::class)]
#[CoversClass(OptionalDuration::class)]
final class ConfigSectionTest extends TestCase
{
    use AssertsReason;

    public function testNestedKeyCarriesItsPath(): void
    {
        $section = ConfigSection::forRoot(['supervision' => ['lag_threshold' => 'soon']])->readSection('supervision');

        $e = $this->assertThrowsReason(ConfigurationError::KeyParseFailed, fn() => $section->readDuration('lag_threshold'));

        self::assertMatchesRegularExpression('/^supervision\.lag_threshold is invalid: /', $e->getMessage());
    }

    public function testDurationBelowItsFloorIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::DurationTooShort, fn() => ConfigSection::forRoot(['timeout' => '10ms'])->readDuration('timeout', Duration::milliseconds(100)));

        self::assertStringContainsString('timeout is 10ms; expected at least 100ms.', $e->getMessage());
    }

    public function testOffSkipsTheFloor(): void
    {
        self::assertNull(ConfigSection::forRoot(['ping' => 'Off'])->readOptionalDuration('ping', Duration::seconds(1))->findDuration());
    }

    public function testWrongTypeNamesWhatWasExpected(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::KeyInvalid, fn() => ConfigSection::forRoot(['workers' => '3'])->readInt('workers'));

        self::assertStringContainsString('workers must be a whole number.', $e->getMessage());
    }

    /** @param array<string, mixed> $yaml */
    #[DataProvider('provideInvalidValues')]
    public function testInvalidValueNamesItsKey(array $yaml, ConfigurationError $reason, string $path): void
    {
        $e = $this->assertThrowsReason($reason, fn() => ConfigFixture::createStewartConfig($yaml));

        self::assertMatchesRegularExpression('/^' . preg_quote($path, '/') . ' /', $e->getMessage());
    }

    /** @return iterable<string, array{array<string, mixed>, ConfigurationError, string}> */
    public static function provideInvalidValues(): iterable
    {
        yield 'negative reconnect delay' => [['reconnect' => ['initial_delay' => -1.0]], ConfigurationError::KeyParseFailed, 'reconnect.initial_delay'];
        yield 'store probed faster than one operation' => [['persistence' => ['url' => 'redis://valkey', 'recovery_interval' => '10ms']], ConfigurationError::DurationTooShort, 'persistence.recovery_interval'];
        yield 'control address in neither shape' => [['control' => ['listen' => 'http://localhost:8080']], ConfigurationError::KeyParseFailed, 'control.listen'];
        yield 'empty control address' => [['control' => ['listen' => '']], ConfigurationError::KeyParseFailed, 'control.listen'];
        yield 'store URL without a scheme' => [['persistence' => ['url' => 'valkey']], ConfigurationError::KeyParseFailed, 'persistence.url'];
        yield 'store prefix read as a pattern' => [['persistence' => ['url' => 'redis://valkey', 'prefix' => 'st*']], ConfigurationError::KeyParseFailed, 'persistence.prefix'];
        yield 'namespace PHP cannot declare' => [['codegen' => ['namespace' => 'Stewart\\1Generated']], ConfigurationError::KeyParseFailed, 'codegen.namespace'];
        yield 'output directory outside the project' => [['codegen' => ['output_dir' => '../elsewhere']], ConfigurationError::KeyParseFailed, 'codegen.output_dir'];
        yield 'zero reconnect delay' => [['reconnect' => ['initial_delay' => '0s']], ConfigurationError::DurationTooShort, 'reconnect.initial_delay'];
        yield 'zero reconnect ceiling' => [['reconnect' => ['max_delay' => '0s']], ConfigurationError::DurationTooShort, 'reconnect.max_delay'];
        yield 'zero restart delay' => [['supervision' => ['restart_initial_delay' => '0s']], ConfigurationError::DurationTooShort, 'supervision.restart_initial_delay'];
        yield 'zero restart ceiling' => [['supervision' => ['restart_max_delay' => '0s']], ConfigurationError::DurationTooShort, 'supervision.restart_max_delay'];
        yield 'zero restart window' => [['supervision' => ['restart_window' => '0s']], ConfigurationError::DurationTooShort, 'supervision.restart_window'];
        yield 'zero lag threshold' => [['supervision' => ['lag_threshold' => '0s']], ConfigurationError::DurationTooShort, 'supervision.lag_threshold'];
        yield 'reconnect ceiling below its start' => [['reconnect' => ['initial_delay' => '5s', 'max_delay' => '1s']], ConfigurationError::MaxDelayBelowInitialDelay, 'reconnect.max_delay'];
        yield 'restart ceiling below its start' => [['supervision' => ['restart_initial_delay' => '5s', 'restart_max_delay' => '1s']], ConfigurationError::MaxDelayBelowInitialDelay, 'supervision.restart_max_delay'];
        yield 'home assistant URL with a misspelled scheme' => [['home_assistant' => ['url' => 'htp://ha.local']], ConfigurationError::KeyParseFailed, 'home_assistant.url'];
        yield 'restart window shorter than the backoff' => [['supervision' => ['restart_window' => '10s']], ConfigurationError::RestartWindowTooShort, 'supervision.restart_window'];
    }

    public function testInvertedBackoffNamesBothKeys(): void
    {
        $e = $this->assertThrowsReason(
            ConfigurationError::MaxDelayBelowInitialDelay,
            fn() => ConfigFixture::createStewartConfig(['reconnect' => ['initial_delay' => '5s', 'max_delay' => '1s']]),
        );

        self::assertStringContainsString('reconnect.max_delay is 1s; expected at least reconnect.initial_delay (5s).', $e->getMessage());
    }

    public function testOptionalDurationDescribesItself(): void
    {
        self::assertSame('off', (string) OptionalDuration::off());
        self::assertSame('1m 30s', (string) OptionalDuration::parse('90s'));
    }
}
