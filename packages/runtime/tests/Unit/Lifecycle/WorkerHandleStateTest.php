<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Lifecycle\WorkerHandleState;

#[CoversClass(WorkerHandleState::class)]
final class WorkerHandleStateTest extends TestCase
{
    /** @return iterable<string, array{WorkerHandleState, WorkerHandleState, bool}> */
    public static function provideTransitions(): iterable
    {
        yield 'starting to ready' => [WorkerHandleState::Starting, WorkerHandleState::Ready, true];
        yield 'ready again' => [WorkerHandleState::Ready, WorkerHandleState::Ready, false];
        yield 'starting to terminated' => [WorkerHandleState::Starting, WorkerHandleState::Terminated, true];
        yield 'ready to terminated' => [WorkerHandleState::Ready, WorkerHandleState::Terminated, true];
        yield 'terminated again' => [WorkerHandleState::Terminated, WorkerHandleState::Terminated, false];
        yield 'terminated to ready' => [WorkerHandleState::Terminated, WorkerHandleState::Ready, false];
        yield 'ready to starting' => [WorkerHandleState::Ready, WorkerHandleState::Starting, false];
        yield 'starting to stopping' => [WorkerHandleState::Starting, WorkerHandleState::Stopping, true];
        yield 'ready to stopping' => [WorkerHandleState::Ready, WorkerHandleState::Stopping, true];
        yield 'stopping to terminated' => [WorkerHandleState::Stopping, WorkerHandleState::Terminated, true];
        yield 'stopping to ready' => [WorkerHandleState::Stopping, WorkerHandleState::Ready, false];
        yield 'terminated to stopping' => [WorkerHandleState::Terminated, WorkerHandleState::Stopping, false];
    }

    #[DataProvider('provideTransitions')]
    public function testMove(WorkerHandleState $from, WorkerHandleState $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canEnter($to));
    }
}
