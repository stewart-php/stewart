<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\Environment\LeafParserChain;
use Stewart\Runtime\Config\EnvironmentPlaceholders;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EnvironmentPlaceholders::class)]
#[CoversClass(LeafParserChain::class)]
final class EnvironmentPlaceholdersTest extends TestCase
{
    use AssertsReason;

    private const array ENVIRONMENT = ['HA_TOKEN' => 'secret', 'HA_HOST' => 'ha.local', 'EMPTY' => '', 'POOL' => '3', 'ROOMS' => '[hall, kitchen]', 'WORD' => 'two'];

    #[DataProvider('provideSubstitutions')]
    public function testPlaceholderIsReplacedByItsVariable(string $written, string $expected): void
    {
        self::assertSame(['value' => $expected], self::resolve(['value' => $written]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideSubstitutions(): iterable
    {
        yield 'the whole value' => ['${HA_TOKEN}', 'secret'];
        yield 'inside a longer value' => ['ws://${HA_HOST}:8123', 'ws://ha.local:8123'];
        yield 'two in one value' => ['${HA_HOST}/${HA_TOKEN}', 'ha.local/secret'];
        yield 'a default for an unset variable' => ['${HA_PORT:-8123}', '8123'];
        yield 'a default for an empty variable' => ['${EMPTY:-fallback}', 'fallback'];
        yield 'a default is ignored when the variable is set' => ['${HA_HOST:-elsewhere}', 'ha.local'];
        yield 'an empty variable without a default is empty' => ['x${EMPTY}y', 'xy'];
        yield 'a doubled dollar escapes' => ['$${HA_TOKEN}', '${HA_TOKEN}'];
        yield 'text without placeholders is untouched' => ['light.$hall', 'light.$hall'];
    }

    public function testResolvesNestedValuesButNotKeysOrNonStrings(): void
    {
        self::assertSame(
            ['apps' => ['${HA_HOST}' => ['options' => ['host' => 'ha.local', 'retries' => 3, 'on' => true, 'rooms' => ['secret']]]]],
            self::resolve(['apps' => ['${HA_HOST}' => ['options' => ['host' => '${HA_HOST}', 'retries' => 3, 'on' => true, 'rooms' => ['${HA_TOKEN}']]]]]),
        );
    }

    public function testUnsetVariableErrorNamesSettingAndVariable(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::PlaceholderUnset, fn() => self::resolve(['home_assistant' => ['token' => '${MISSING}']]));

        self::assertStringContainsString('home_assistant.token refers to ${MISSING}, which is not set.', $e->getMessage());
    }

    /**
     * @param array<array-key, mixed> $file
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('provideTypedValues')]
    public function testWholePlaceholderIsTypedByItsSetting(array $file, array $expected): void
    {
        self::assertSame($expected, self::resolve($file));
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}> */
    public static function provideTypedValues(): iterable
    {
        yield 'an integer setting' => [['workers' => '${POOL}'], ['workers' => 3]];
        yield 'an integer from the default' => [['workers' => '${UNSET:-2}'], ['workers' => 2]];
        yield 'a boolean setting' => [['apps' => ['demo' => ['enabled' => '${UNSET:-off}']]], ['apps' => ['demo' => ['enabled' => false]]]];
        yield 'a list setting' => [['codegen' => ['include' => '${ROOMS}']], ['codegen' => ['include' => ['hall', 'kitchen']]]];
        yield 'a string setting stays text' => [['home_assistant' => ['token' => '${POOL}']], ['home_assistant' => ['token' => '3']]];
        yield 'a hyphenated key' => [['worker-event-buffer' => '${POOL}'], ['worker-event-buffer' => 3]];
    }

    public function testMixedValueStaysAString(): void
    {
        self::assertSame(['workers' => '30'], self::resolve(['workers' => '${POOL}0']));
    }

    public function testEscapedPlaceholderIsNotTyped(): void
    {
        self::assertSame(['workers' => '${POOL}'], self::resolve(['workers' => '$${POOL}']));
    }

    public function testTypingFailureNamesSettingAndVariable(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => self::resolve(['workers' => '${WORD}']));

        self::assertStringContainsString('workers (${WORD}) is "two"; expected a whole number.', $e->getMessage());
    }

    public function testFreeFormOptionIsInferred(): void
    {
        self::assertSame(
            ['apps' => ['demo' => ['options' => ['count' => 3, 'rooms' => ['hall', 'kitchen'], 'nested' => ['count' => 3]]]]],
            self::resolve(['apps' => ['demo' => ['options' => ['count' => '${POOL}', 'rooms' => '${ROOMS}', 'nested' => ['count' => '${POOL}']]]]]),
        );
    }

    public function testNodeWithoutParserKeepsTheString(): void
    {
        self::assertSame(['apps' => '3'], self::resolve(['apps' => '${POOL}']));
    }

    /**
     * @param array<array-key, mixed> $file
     * @return array<array-key, mixed>
     */
    private static function resolve(array $file): array
    {
        return ConfigLoaderFixture::createPlaceholders(new EnvironmentVariables(self::ENVIRONMENT))->resolve(new StewartConfigSchema()->buildConfigTree(), $file);
    }
}
