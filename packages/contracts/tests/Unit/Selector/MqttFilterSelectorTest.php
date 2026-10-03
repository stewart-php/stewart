<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Selector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\SelectorError;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Selector\SelectorKind;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(Selector::class)]
#[CoversClass(SelectorException::class)]
final class MqttFilterSelectorTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideTopicMatches')]
    public function testFilterMatchesTopicPerMqttRules(string $filter, string $topic, bool $expected): void
    {
        self::assertSame($expected, Selector::mqttFilter($filter)->matches($topic));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function provideTopicMatches(): iterable
    {
        yield 'exact level' => ['home/kitchen/temp', 'home/kitchen/temp', true];
        yield 'exact is literal' => ['home/kitchen.temp', 'home/kitchenxtemp', false];
        yield 'plus spans one level' => ['home/+/temp', 'home/kitchen/temp', true];
        yield 'plus never spans two levels' => ['home/+/temp', 'home/a/b/temp', false];
        yield 'plus matches empty level' => ['home/+/temp', 'home//temp', true];
        yield 'hash matches descendants' => ['home/#', 'home/a/b/c', true];
        yield 'hash matches parent level' => ['home/#', 'home', true];
        yield 'hash requires prefix' => ['home/#', 'homes/a', false];
        yield 'bare hash matches all' => ['#', 'any/topic', true];
        yield 'bare hash skips dollar topics' => ['#', '$SYS/broker/load', false];
        yield 'leading plus skips dollar topics' => ['+/broker/load', '$SYS/broker/load', false];
        yield 'explicit dollar level matches' => ['$SYS/#', '$SYS/broker/load', true];
    }

    public function testFilterKeepsItsKindAndCanonicalKey(): void
    {
        $selector = Selector::mqttFilter('home/+/temp');

        self::assertSame(SelectorKind::MqttFilter, $selector->getKind());
        self::assertSame('home/+/temp', $selector->getPattern());
        self::assertSame('mqtt_filter:home/+/temp', $selector->toCanonicalKey());
    }

    #[DataProvider('provideInvalidFilters')]
    public function testMalformedWildcardIsRejected(string $filter): void
    {
        self::assertThrowsReason(SelectorError::MqttFilterInvalid, static fn() => Selector::mqttFilter($filter));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidFilters(): iterable
    {
        yield 'hash not last' => ['home/#/temp'];
        yield 'hash inside level' => ['home/kit#'];
        yield 'plus inside level' => ['home/kit+chen'];
        yield 'nul character' => ["home/\0"];
    }

    public function testEmptyFilterIsRejected(): void
    {
        self::assertThrowsReason(SelectorError::Empty, static fn() => Selector::mqttFilter(''));
    }
}
