<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Attribute\AttributeKind;

#[CoversClass(AttributeKind::class)]
final class AttributeKindTest extends TestCase
{
    /** @return iterable<string, array{AttributeKind, string, string, string}> */
    public static function provideKinds(): iterable
    {
        yield 'bool' => [AttributeKind::Bool, 'getBoolAttribute', '?bool', '?bool'];
        yield 'float' => [AttributeKind::Float, 'getFloatAttribute', '?float', '?float'];
        yield 'string' => [AttributeKind::String, 'getStringAttribute', '?string', '?string'];
        yield 'array' => [AttributeKind::Array, 'getArrayAttribute', '?array', 'array<array-key, mixed>|null'];
        yield 'mixed' => [AttributeKind::Mixed, 'getAttribute', 'mixed', 'mixed'];
    }

    #[DataProvider('provideKinds')]
    public function testKindNamesReaderAndType(AttributeKind $kind, string $reader, string $native, string $docblock): void
    {
        self::assertSame($reader, $kind->getReaderMethodName());
        self::assertSame($native, $kind->getPhpType()->native);
        self::assertSame($docblock, $kind->getPhpType()->docblock);
    }
}
