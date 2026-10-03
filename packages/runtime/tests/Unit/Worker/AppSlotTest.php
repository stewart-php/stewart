<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\Healthy;
use Stewart\Runtime\Worker\AppSlot;

#[CoversClass(AppSlot::class)]
final class AppSlotTest extends TestCase
{
    public function testAllowedMoveChangesTheState(): void
    {
        $slot = self::createSlot();

        $slot->moveTo(AppState::Constructing);

        self::assertSame(AppState::Constructing, $slot->state);
    }

    public function testInvalidMoveThrows(): void
    {
        $slot = self::createSlot();

        $this->expectException(LogicException::class);

        $slot->moveTo(AppState::Running);
    }

    public function testConstructionRequiresTheConstructingState(): void
    {
        $slot = self::createSlot();

        $this->expectException(LogicException::class);

        $slot->markConstructed(new Healthy());
    }

    public function testDisposeEndingAfterAbandonKeepsAbandoned(): void
    {
        $slot = self::createSlot();
        $slot->moveTo(AppState::Constructing);
        $slot->markConstructed(new Healthy());
        $slot->moveTo(AppState::Initializing);
        $slot->moveTo(AppState::Running);

        self::assertTrue($slot->claimDisposal());
        self::assertFalse($slot->claimDisposal(), 'Disposal is claimed once.');

        $slot->moveTo(AppState::Abandoned);
        $slot->markDisposed();

        self::assertSame(AppState::Abandoned, $slot->state);
    }

    private static function createSlot(): AppSlot
    {
        return new AppSlot(new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []));
    }
}
