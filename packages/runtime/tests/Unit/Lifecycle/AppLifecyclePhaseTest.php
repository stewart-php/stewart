<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Lifecycle\AppLifecyclePhase;

#[CoversClass(AppLifecyclePhase::class)]
final class AppLifecyclePhaseTest extends TestCase
{
    /** @return iterable<string, array{AppLifecyclePhase, AppLifecyclePhase, bool}> */
    public static function provideTransitions(): iterable
    {
        yield 'starting to stopping' => [AppLifecyclePhase::Starting, AppLifecyclePhase::Stopping, true];
        yield 'stopping again' => [AppLifecyclePhase::Stopping, AppLifecyclePhase::Stopping, false];
        yield 'stopping to closed' => [AppLifecyclePhase::Stopping, AppLifecyclePhase::Closed, true];
        yield 'starting to closed' => [AppLifecyclePhase::Starting, AppLifecyclePhase::Closed, false];
        yield 'closed to stopping' => [AppLifecyclePhase::Closed, AppLifecyclePhase::Stopping, false];
        yield 'closed again' => [AppLifecyclePhase::Closed, AppLifecyclePhase::Closed, false];
    }

    #[DataProvider('provideTransitions')]
    public function testMove(AppLifecyclePhase $from, AppLifecyclePhase $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canEnter($to));
    }
}
