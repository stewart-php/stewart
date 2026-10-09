<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Exception\HaClientError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ComponentInstance::class)]
final class ComponentInstanceTest extends TestCase
{
    use AssertsReason;

    public function testLowercaseNameIsAccepted(): void
    {
        self::assertSame('upstairs_2', ComponentInstance::parse('upstairs_2')->value);
    }

    #[DataProvider('provideInvalidNames')]
    public function testNameOutsidePatternIsRejected(string $name): void
    {
        $this->assertThrowsReason(HaClientError::ComponentInstanceInvalid, static fn() => ComponentInstance::parse($name));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['2nd'];
        yield 'uppercase' => ['Default'];
        yield 'dash breaks unique_id' => ['up-stairs'];
        yield 'over 64 characters' => [str_repeat('a', 65)];
    }
}
