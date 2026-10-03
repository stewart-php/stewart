<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Text;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Support\Text\ClosestNameFinder;

#[CoversClass(ClosestNameFinder::class)]
final class ClosestNameFinderTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, ?string}> */
    public static function provideLookups(): iterable
    {
        yield 'one typo' => ['boilr', ['heating', 'boiler'], 'boiler'];
        yield 'short names allow two edits' => ['ha', ['log', 'hass'], 'hass'];
        yield 'short names reject three edits' => ['ha', ['store'], null];
        yield 'long names allow a third of their length' => ['home_asistant_tokn', ['home_assistant_token'], 'home_assistant_token'];
        yield 'nothing close' => ['lights', ['persistence', 'control'], null];
        yield 'no candidates' => ['boiler', [], null];
        yield 'a tie keeps the first candidate' => ['cat', ['bat', 'hat'], 'bat'];
        yield 'an exact match wins' => ['log', ['logs', 'log'], 'log'];
    }

    /** @param list<string> $candidates */
    #[DataProvider('provideLookups')]
    public function testFindsTheClosestName(string $name, array $candidates, ?string $expected): void
    {
        self::assertSame($expected, new ClosestNameFinder()->findClosestName($name, $candidates));
    }
}
