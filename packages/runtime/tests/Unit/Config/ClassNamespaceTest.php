<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ClassNamespace;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ClassNamespace::class)]
final class ClassNamespaceTest extends TestCase
{
    use AssertsReason;

    public function testSurroundingBackslashesAreDropped(): void
    {
        self::assertSame('Acme\Home', new ClassNamespace('\Acme\Home\\')->value);
    }

    public function testSomethingThatIsNotANamespaceIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => new ClassNamespace('Acme Home'));

        self::assertStringContainsString('"Acme Home" is not a PHP namespace', $e->getMessage());
    }

    public function testEmptyNamespaceIsRefused(): void
    {
        $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => new ClassNamespace('\\'));
    }
}
