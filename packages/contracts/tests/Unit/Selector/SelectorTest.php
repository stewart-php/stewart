<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Selector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\SelectorError;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Selector\SelectorKind;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(Selector::class)]
#[CoversClass(SelectorException::class)]
final class SelectorTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideSelectorPatterns')]
    public function testStringIsExactWithoutWildcard(string $pattern, SelectorKind $expected): void
    {
        self::assertSame($expected, Selector::fromSpec($pattern)->getKind());
    }

    /** @return iterable<string, array{string, SelectorKind}> */
    public static function provideSelectorPatterns(): iterable
    {
        yield 'plain entity id' => ['light.hall', SelectorKind::Exact];
        yield 'star glob' => ['sensor.*_battery', SelectorKind::Glob];
        yield 'question glob' => ['light.kitchen_?', SelectorKind::Glob];
        yield 'bare star' => ['*', SelectorKind::Glob];
        yield 'dotted topic' => ['house.away', SelectorKind::Exact];
        yield 'what looks like a regex' => ['/alarm/', SelectorKind::Exact];
    }

    public function testRegexIsOnlyEverAskedFor(): void
    {
        $selector = Selector::regex('/^light\./i');

        self::assertTrue($selector->matches('LIGHT.hall'));
        self::assertFalse(Selector::fromSpec('/alarm/')->matches('xalarmx'));
        self::assertTrue(Selector::fromSpec('/alarm/')->matches('/alarm/'));
    }

    public function testAnyMatchesEverythingAndNamesItself(): void
    {
        self::assertTrue(Selector::any()->isAny());
        self::assertTrue(Selector::fromSpec('*')->isAny());
        self::assertTrue(Selector::any()->matches(''));
        self::assertSame('any', Selector::any()->toCanonicalKey());
        self::assertNull(Selector::any()->findExactPattern());
    }

    public function testExactMatchesOnlyItself(): void
    {
        $selector = Selector::exact('light.hall');

        self::assertTrue($selector->matches('light.hall'));
        self::assertFalse($selector->matches('light.hall_2'));
        self::assertFalse($selector->matches('switch.hall'));
        self::assertSame('light.hall', $selector->findExactPattern());
    }

    public function testGlobAnchorsAtBothEnds(): void
    {
        $selector = Selector::glob('light.*');

        self::assertTrue($selector->matches('light.hall'));
        self::assertTrue($selector->matches('light.a.b'));
        self::assertFalse($selector->matches('switch.light.thing'));
        self::assertFalse($selector->matches('x_light.hall'));
        self::assertFalse($selector->matches("light.hall\n"));
    }

    public function testGlobMatchesAcrossDomains(): void
    {
        $selector = Selector::glob('*_battery');

        self::assertTrue($selector->matches('sensor.phone_battery'));
        self::assertTrue($selector->matches('binary_sensor.door_battery'));
        self::assertFalse($selector->matches('sensor.phone_batteries'));
    }

    public function testGlobTreatsRegexMetacharactersAsLiteral(): void
    {
        $selector = Selector::glob('light.hall*');

        self::assertTrue($selector->matches('light.hall_ceiling'));
        self::assertFalse($selector->matches('lightXhall_ceiling'));
    }

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        $selector = Selector::glob('light.kitchen_?');

        self::assertTrue($selector->matches('light.kitchen_1'));
        self::assertFalse($selector->matches('light.kitchen_12'));
        self::assertFalse($selector->matches('light.kitchen_'));
    }

    public function testEmptyGlobIsRejected(): void
    {
        $this->assertThrowsReason(SelectorError::Empty, fn() => Selector::glob(''));
    }

    public function testEmptyExactIsRejected(): void
    {
        $this->assertThrowsReason(SelectorError::Empty, fn() => Selector::fromSpec(''));
    }

    public function testInvalidRegexIsRejectedAtConstruction(): void
    {
        $this->assertThrowsReason(SelectorError::RegexInvalid, fn() => Selector::regex('#^sensor\.(unclosed#'));
    }

    public function testInvalidRegexNamesItsPattern(): void
    {
        $e = $this->assertThrowsReason(SelectorError::RegexInvalid, fn() => Selector::regex('#^sensor\.(unclosed#'));

        self::assertStringContainsString('#^sensor\.(unclosed#', $e->getMessage());
    }

    public function testAnyOfMatchesIfAnyMemberDoes(): void
    {
        $selector = Selector::anyOf(
            Selector::exact('light.hall'),
            Selector::glob('sensor.*_battery')
        );

        self::assertTrue($selector->matches('light.hall'));
        self::assertTrue($selector->matches('sensor.phone_battery'));
        self::assertFalse($selector->matches('switch.porch'));
    }

    public function testAnyOfRejectsAnEmptyList(): void
    {
        $this->assertThrowsReason(SelectorError::Empty, fn() => Selector::anyOf());
    }

    public function testAnyOfKeysCannotCollideOnTheSeparator(): void
    {
        $two = Selector::anyOf(Selector::glob('a*'), Selector::glob('b*'));
        $one = Selector::anyOf(Selector::glob('a*|glob:b*'));

        self::assertNotSame($two->toCanonicalKey(), $one->toCanonicalKey());
    }

    public function testFromCollapsesASingleItemList(): void
    {
        self::assertSame(SelectorKind::Exact, Selector::fromSpec(SelectorCollection::fromSpecs('light.hall'))->getKind());
        self::assertSame(SelectorKind::AnyOf, Selector::fromSpec(SelectorCollection::fromSpecs('light.hall', 'light.porch'))->getKind());
        self::assertSame('light.hall', Selector::anyOf(Selector::exact('light.hall'))->findExactPattern());
    }

    public function testKeysAreStableAndOrderIndependent(): void
    {
        $a = Selector::anyOf(Selector::exact('a.b'), Selector::exact('c.d'));
        $b = Selector::anyOf(Selector::exact('c.d'), Selector::exact('a.b'));

        self::assertSame($a->toCanonicalKey(), $b->toCanonicalKey());
        self::assertNotSame(Selector::exact('light.hall')->toCanonicalKey(), Selector::glob('light.hall*')->toCanonicalKey());
    }

    public function testSelectorsSurviveSerialization(): void
    {
        foreach ([
            Selector::exact('light.hall'),
            Selector::glob('sensor.*_battery'),
            Selector::regex('#^light\.#'),
            Selector::any(),
            Selector::anyOf(Selector::exact('a.b'), Selector::glob('c.*')),
        ] as $selector) {
            $restored = unserialize(serialize($selector));

            self::assertInstanceOf(Selector::class, $restored);
            self::assertSame($selector->toCanonicalKey(), $restored->toCanonicalKey());
            self::assertSame($selector->matches('sensor.phone_battery'), $restored->matches('sensor.phone_battery'));
        }
    }

    public function testHasExactPatternLooksIntoAnyOfMembers(): void
    {
        self::assertTrue(Selector::fromSpec('state_changed')->hasExactPattern('state_changed'));
        self::assertTrue(Selector::fromSpec(SelectorCollection::fromSpecs('zha_event', 'state_changed'))->hasExactPattern('state_changed'));
        self::assertFalse(Selector::fromSpec('state_*')->hasExactPattern('state_changed'));
        self::assertFalse(Selector::fromSpec('zha_event')->hasExactPattern('state_changed'));
    }
}
