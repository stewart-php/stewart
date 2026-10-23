<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Action\ActionName;
use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ActionName::class)]
final class ActionNameTest extends TestCase
{
    use AssertsReason;

    public function testSplitsIntoDomainAndAction(): void
    {
        $name = new ActionName('persistent_notification.create');

        self::assertSame('persistent_notification', $name->domain);
        self::assertSame('create', $name->action);
        self::assertSame('persistent_notification.create', (string) $name);
    }

    /** @return iterable<string, array{string}> */
    public static function provideMalformedNames(): iterable
    {
        yield 'no domain' => ['turn_on'];
        yield 'two dots' => ['light.turn_on.now'];
        yield 'empty action' => ['light.'];
        yield 'upper case' => ['Light.Turn_On'];
        yield 'slash as in AppDaemon' => ['light/turn_on'];
    }

    #[DataProvider('provideMalformedNames')]
    public function testMalformedNameIsRefused(string $name): void
    {
        $this->assertThrowsReason(ActionCallError::ActionNameInvalid, static fn() => new ActionName($name));
    }
}
