<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Php;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Php\Identifier;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function provideIdentifierCases(): iterable
    {
        yield 'snake case' => ['hall_ceiling', 'hallCeiling'];
        yield 'already one word' => ['pump', 'pump'];
        yield 'leading digit' => ['1st_floor', '_1stFloor'];
        yield 'digits inside' => ['porch_2', 'porch2'];
        yield 'mixed case input' => ['Hall_Ceiling', 'hallCeiling'];
        yield 'punctuation' => ['hall-ceiling.left', 'hallCeilingLeft'];
        yield 'nothing usable' => ['--', '_'];
        yield 'accented letters' => ['Hőfok_érzékelő', 'hofokErzekelo'];
        yield 'ligatures spell out' => ['Straße', 'strasse'];
        yield 'stroked and slashed letters' => ['łódź_øre', 'lodzOre'];
        yield 'ash and thorn' => ['æther_þorn', 'aetherThorn'];
        yield 'dotted and dotless i' => ['İzmir_ılık', 'izmirIlik'];
    }

    #[DataProvider('provideIdentifierCases')]
    public function testMemberNames(string $raw, string $expected): void
    {
        self::assertSame($expected, Identifier::convertToCamelCase($raw));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideLeadingCharacters(): iterable
    {
        yield 'snake case' => ['local_time', true];
        yield 'spaced words' => ['Local Time', true];
        yield 'leading underscore' => ['_private', true];
        yield 'a date' => ['06-19 13:01', false];
        yield 'leading digit' => ['1st_floor', false];
        yield 'nothing usable' => ['--', false];
    }

    #[DataProvider('provideLeadingCharacters')]
    public function testOnlyAKeyWhoseFirstWordStartsWithALetterIsAName(string $raw, bool $expected): void
    {
        self::assertSame($expected, Identifier::startsWithLetter($raw));
    }

    public function testClassStemsArePascalCase(): void
    {
        self::assertSame('BinarySensor', Identifier::convertToPascalCase('binary_sensor'));
        self::assertSame('Switch', Identifier::convertToPascalCase('switch'));
        self::assertSame('_3dPrinter', Identifier::convertToPascalCase('3d_printer'));
        self::assertSame('_', Identifier::convertToPascalCase('--'));
    }
}
