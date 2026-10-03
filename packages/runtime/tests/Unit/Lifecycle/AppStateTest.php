<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Lifecycle\AppState;

#[CoversClass(AppState::class)]
final class AppStateTest extends TestCase
{
    /** @return iterable<string, array{AppState, AppState}> */
    public static function provideAllowedMoves(): iterable
    {
        yield 'declared to constructing' => [AppState::Declared, AppState::Constructing];
        yield 'declared to failed' => [AppState::Declared, AppState::Failed];
        yield 'constructing to constructed' => [AppState::Constructing, AppState::Constructed];
        yield 'constructed to skipped' => [AppState::Constructed, AppState::Skipped];
        yield 'initializing to initialized' => [AppState::Initializing, AppState::Initialized];
        yield 'initializing to running' => [AppState::Initializing, AppState::Running];
        yield 'running to disposing' => [AppState::Running, AppState::Disposing];
        yield 'disposing to disposed' => [AppState::Disposing, AppState::Disposed];
        yield 'disposing to abandoned' => [AppState::Disposing, AppState::Abandoned];
        yield 'initialized to abandoned' => [AppState::Initialized, AppState::Abandoned];
    }

    /** @return iterable<string, array{AppState, AppState}> */
    public static function provideDeniedMoves(): iterable
    {
        yield 'declared to running' => [AppState::Declared, AppState::Running];
        yield 'constructed to failed' => [AppState::Constructed, AppState::Failed];
        yield 'running to failed' => [AppState::Running, AppState::Failed];
        yield 'running to running' => [AppState::Running, AppState::Running];
        yield 'running to disposed' => [AppState::Running, AppState::Disposed];
        yield 'failed to disposed' => [AppState::Failed, AppState::Disposed];
        yield 'abandoned to failed' => [AppState::Abandoned, AppState::Failed];
    }

    #[DataProvider('provideAllowedMoves')]
    public function testAllowedMove(AppState $from, AppState $to): void
    {
        self::assertTrue($from->canEnter($to));
    }

    #[DataProvider('provideDeniedMoves')]
    public function testDeniedMove(AppState $from, AppState $to): void
    {
        self::assertFalse($from->canEnter($to));
    }

    public function testTerminalStatesLeadNowhere(): void
    {
        foreach (AppState::cases() as $state) {
            $exits = array_filter(AppState::cases(), $state->canEnter(...));

            self::assertSame($state->isTerminal(), $exits === [], $state->value);
        }
    }
}
