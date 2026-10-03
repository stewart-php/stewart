<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Lifecycle\WorkerPhase;

#[CoversClass(WorkerPhase::class)]
final class WorkerPhaseTest extends TestCase
{
    private const array ALLOWED = [
        'stopped' => ['spawning', 'restart_scheduled', 'quarantined'],
        'spawning' => ['live', 'stopped'],
        'live' => ['stopped'],
        'restart_scheduled' => ['spawning', 'stopped'],
        'quarantined' => ['spawning', 'stopped'],
    ];

    /** @return iterable<string, array{WorkerPhase, WorkerPhase, bool}> */
    public static function provideTransitions(): iterable
    {
        foreach (WorkerPhase::cases() as $from) {
            foreach (WorkerPhase::cases() as $to) {
                yield $from->value . ' to ' . $to->value => [$from, $to, \in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }
    }

    #[DataProvider('provideTransitions')]
    public function testMoveFollowsTheTransitionTable(WorkerPhase $from, WorkerPhase $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canEnter($to));
    }
}
