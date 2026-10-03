<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Php\PhpType;
use Stewart\Codegen\Service\ServiceSelector;
use Stewart\Codegen\Snapshot\RawMap;

#[CoversClass(ServiceSelector::class)]
#[CoversClass(PhpType::class)]
#[CoversClass(RawMap::class)]
final class ServiceSelectorTest extends TestCase
{
    private const string DURATION = 'array{days?: int|float, hours?: int|float, minutes?: int|float, seconds?: int|float, milliseconds?: int|float}|string|int|float';

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function provideSelectors(): iterable
    {
        yield 'number' => [['number' => ['min' => 0, 'max' => 255]], 'int|float', 'int|float'];
        yield 'color temperature' => [['color_temp' => ['unit' => 'kelvin']], 'int|float', 'int|float'];
        yield 'boolean' => [['boolean' => []], 'bool', 'bool'];
        yield 'text' => [['text' => []], 'string|int|float', 'string|int|float'];
        yield 'entity' => [['entity' => ['domain' => 'light']], 'string', 'string'];
        yield 'rgb color' => [['color_rgb' => []], 'array', 'array{int, int, int}'];
        yield 'object' => [['object' => []], 'array', 'array<array-key, mixed>'];
        yield 'unknown kind' => [['whatever_comes_next' => []], 'mixed', 'mixed'];
        yield 'unknown kind multiple' => [['whatever_comes_next' => ['multiple' => true]], 'array', 'list<mixed>'];
        yield 'statistic' => [['statistic' => []], 'string', 'string'];
        yield 'ui color' => [['ui_color' => []], 'string', 'string'];
        yield 'duration' => [['duration' => []], 'array|string|int|float', self::DURATION];
        yield 'constant true' => [['constant' => ['value' => true, 'label' => 'Enabled']], 'bool', 'true'];
        yield 'constant string' => [['constant' => ['value' => 'auto']], 'string', "'auto'"];
        yield 'constant int' => [['constant' => ['value' => 3]], 'int', '3'];
        yield 'constant without value' => [['constant' => ['label' => 'Enabled']], 'mixed', 'mixed'];
        yield 'select option zero' => [['select' => ['options' => [['value' => '0', 'label' => 'Off'], ['value' => '1', 'label' => 'On']]]], 'string', "'0'|'1'"];
        yield 'trigger' => [['trigger' => []], 'array', 'array<array-key, mixed>'];
        yield 'multiple' => [['entity' => ['multiple' => true]], 'array', 'list<string>'];
        yield 'select options' => [['select' => ['options' => ['long', 'short']]], 'string', "'long'|'short'"];
        yield 'select options with labels' => [['select' => ['options' => [['value' => 'daily', 'label' => 'Daily'], ['value' => 'hourly', 'label' => 'Hourly']]]], 'string', "'daily'|'hourly'"];
        yield 'select multiple' => [['select' => ['options' => ['a', 'b'], 'multiple' => true]], 'array', "list<'a'|'b'>"];
        yield 'select with a custom value' => [['select' => ['options' => ['a'], 'custom_value' => true]], 'string', 'string'];
        yield 'select without options' => [['select' => []], 'string', 'string'];
        yield 'select option with a quote' => [['select' => ['options' => ["it's"]]], 'string', "'it\\'s'"];
        yield 'no selector' => [[], 'mixed', 'mixed'];
        yield 'kind with null config' => [['text' => null], 'string|int|float', 'string|int|float'];
        yield 'kind with string config' => [['entity' => 'light'], 'string', 'string'];
        yield 'kind with scalar config' => [['number' => 5], 'int|float', 'int|float'];
        yield 'unknown kind with string config' => [['whatever_comes_next' => 'x'], 'mixed', 'mixed'];
    }

    /** @param array<string, mixed> $selector */
    #[DataProvider('provideSelectors')]
    public function testSelectorKinds(array $selector, string $native, string $docblock): void
    {
        $type = new ServiceSelector(RawMap::fromValue($selector))->resolvePhpType();

        self::assertSame($native, $type->native);
        self::assertSame($docblock, $type->docblock);
    }

    public function testNullabilityKeepsTheDocblockReadable(): void
    {
        self::assertSame('?string', new ServiceSelector(RawMap::fromValue(['entity' => []]))->resolvePhpType()->nullable()->native);
        self::assertSame('?string', new ServiceSelector(RawMap::fromValue(['entity' => []]))->resolvePhpType()->nullable()->docblock);

        $rgb = new ServiceSelector(RawMap::fromValue(['color_rgb' => []]))->resolvePhpType()->nullable();
        self::assertSame('?array', $rgb->native);
        self::assertSame('array{int, int, int}|null', $rgb->docblock);

        $select = new ServiceSelector(RawMap::fromValue(['select' => ['options' => ['long', 'short']]]))->resolvePhpType()->nullable();
        self::assertSame('?string', $select->native);
        self::assertSame("'long'|'short'|null", $select->docblock);

        $number = new ServiceSelector(RawMap::fromValue(['number' => []]))->resolvePhpType()->nullable();
        self::assertSame('int|float|null', $number->native);
        self::assertFalse($number->needsDocblock());
    }

    public function testNullableConstantKeepsItsLiteral(): void
    {
        $constant = new ServiceSelector(RawMap::fromValue(['constant' => ['value' => true]]))->resolvePhpType()->nullable();

        self::assertSame('?bool', $constant->native);
        self::assertSame('true|null', $constant->docblock);
    }

    public function testNullableDurationKeepsTheUnion(): void
    {
        $duration = new ServiceSelector(RawMap::fromValue(['duration' => []]))->resolvePhpType()->nullable();

        self::assertSame('array|string|int|float|null', $duration->native);
        self::assertSame(self::DURATION . '|null', $duration->docblock);
    }

    public function testMixedIsAlreadyNullable(): void
    {
        self::assertSame('mixed', PhpType::mixed()->nullable()->native);
    }
}
