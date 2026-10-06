<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(AppId::class)]
final class AppIdTest extends TestCase
{
    use AssertsReason;

    public function testHyphenAndUnderscoreCollide(): void
    {
        $hyphen = new AppId('hall-light');
        $underscore = new AppId('hall_light');

        self::assertTrue($hyphen->collidesWith($underscore));
        self::assertFalse($hyphen->equals($underscore));
        self::assertTrue($hyphen->equals(new AppId('hall-light')));
        self::assertSame('hall_light', $hyphen->toEnvironmentKey());
    }

    public function testRejectsMalformedIds(): void
    {
        self::assertNull(AppId::tryFromString('1st'));
        self::assertNull(AppId::tryFromString('@shared'));
        self::assertNull(AppId::tryFromString("demo\n"));

        $this->assertThrowsReason(IdentifierError::AppIdInvalid, fn() => new AppId('Demo'));
    }

    public function testRejectsIdOverLengthLimit(): void
    {
        self::assertSame(100, \strlen(new AppId(str_repeat('a', 100))->value));
        self::assertNull(AppId::tryFromString(str_repeat('a', 101)));

        $this->assertThrowsReason(IdentifierError::AppIdTooLong, fn() => new AppId(str_repeat('a', 101)));
    }
}
