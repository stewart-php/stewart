<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotState;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerSlotState::class)]
final class WorkerSlotStateTest extends TestCase
{
    private ManualTimers $timers;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
    }

    public function testSlotStartsStopped(): void
    {
        $state = self::createState();

        self::assertSame(WorkerPhase::Stopped, $state->phase);
        self::assertNull($state->handle);
        self::assertNull($state->restartDueAt);
    }

    #[DataProvider('provideAllowedTransitions')]
    public function testAllowedTransitionMovesThePhase(WorkerPhase $from, string $transition, WorkerPhase $to): void
    {
        $state = $this->createStateInPhase($from);

        $this->apply($state, $transition);

        self::assertSame($to, $state->phase);
    }

    /** @return iterable<string, array{WorkerPhase, string, WorkerPhase}> */
    public static function provideAllowedTransitions(): iterable
    {
        yield 'spawn from stopped' => [WorkerPhase::Stopped, 'beginSpawn', WorkerPhase::Spawning];
        yield 'spawn when a restart falls due' => [WorkerPhase::RestartScheduled, 'beginSpawn', WorkerPhase::Spawning];
        yield 'spawn out of quarantine' => [WorkerPhase::Quarantined, 'beginSpawn', WorkerPhase::Spawning];
        yield 'spawn fails' => [WorkerPhase::Spawning, 'markSpawnFailed', WorkerPhase::Stopped];
        yield 'recordSpawnedHandle' => [WorkerPhase::Spawning, 'recordSpawnedHandle', WorkerPhase::Live];
        yield 'markGone' => [WorkerPhase::Live, 'markGone', WorkerPhase::Stopped];
        yield 'restart scheduled' => [WorkerPhase::Stopped, 'recordScheduledRestart', WorkerPhase::RestartScheduled];
        yield 'markQuarantined' => [WorkerPhase::Stopped, 'markQuarantined', WorkerPhase::Quarantined];

        foreach (WorkerPhase::cases() as $phase) {
            yield 'stop from ' . $phase->value => [$phase, 'stop', WorkerPhase::Stopped];
        }
    }

    #[DataProvider('provideRefusedTransitions')]
    public function testRefusedTransitionThrowsAndLeavesThePhase(WorkerPhase $from, string $transition): void
    {
        $state = $this->createStateInPhase($from);

        try {
            $this->apply($state, $transition);
            self::fail('The transition should have been refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('cannot move from ' . $from->value, $e->getMessage());
        }

        self::assertSame($from, $state->phase);
    }

    /** @return iterable<string, array{WorkerPhase, string}> */
    public static function provideRefusedTransitions(): iterable
    {
        $allowedFrom = [
            'beginSpawn' => [WorkerPhase::Stopped, WorkerPhase::RestartScheduled, WorkerPhase::Quarantined],
            'markSpawnFailed' => [WorkerPhase::Spawning],
            'recordSpawnedHandle' => [WorkerPhase::Spawning],
            'markGone' => [WorkerPhase::Live],
            'recordScheduledRestart' => [WorkerPhase::Stopped],
            'markQuarantined' => [WorkerPhase::Stopped],
        ];

        foreach ($allowedFrom as $transition => $allowed) {
            foreach (WorkerPhase::cases() as $phase) {
                if (!\in_array($phase, $allowed, true)) {
                    yield $transition . ' from ' . $phase->value => [$phase, $transition];
                }
            }
        }
    }

    public function testSpawnReturnsReplacedHandle(): void
    {
        $state = $this->createStateInPhase(WorkerPhase::Live);
        $previous = $state->handle;
        self::assertNotNull($previous);
        $state->markGone();

        $replacement = self::createHandle();
        $state->beginSpawn();

        self::assertSame($previous, $state->recordSpawnedHandle($replacement, null));
        self::assertSame($replacement, $state->handle);
        self::assertFalse($previous->isTerminated(), 'The state is pure; the pool terminates what it hands back.');
        self::assertFalse($replacement->isTerminated());
    }

    public function testOnlyTheCurrentHandleIsLive(): void
    {
        $state = $this->createStateInPhase(WorkerPhase::Live);
        $current = $state->handle;
        self::assertNotNull($current);

        self::assertTrue($state->isLive($current));
        self::assertFalse($state->isLive(self::createHandle()));

        $state->markGone();

        self::assertFalse($state->isLive($current));
    }

    public function testReadyAndGoneCancelTheReadyDeadline(): void
    {
        foreach (['cancelReadyDeadline', 'markGone'] as $transition) {
            $state = self::createState();
            $state->beginSpawn();
            $deadline = $this->createTimer();
            $state->recordSpawnedHandle(self::createHandle(), $deadline);

            $this->apply($state, $transition);

            self::assertFalse($deadline->isPending());
        }
    }

    public function testRestartKeepsItsDueTimeUntilItIsStopped(): void
    {
        $state = self::createState();
        $restart = $this->createTimer();
        $state->recordScheduledRestart($restart, self::getDueAt());

        self::assertEquals(self::getDueAt(), $state->restartDueAt);
        self::assertTrue($restart->isPending());

        $state->stop();

        self::assertNull($state->restartDueAt);
        self::assertFalse($restart->isPending());
    }

    public function testSpawningCancelsAPendingRestart(): void
    {
        $state = self::createState();
        $restart = $this->createTimer();
        $state->recordScheduledRestart($restart, self::getDueAt());

        $state->beginSpawn();

        self::assertFalse($restart->isPending());
        self::assertNull($state->restartDueAt);
    }

    public function testStopCancelsEveryTimerItOwns(): void
    {
        $live = self::createState();
        $live->beginSpawn();
        $live->recordSpawnedHandle(self::createHandle(), $this->createTimer());

        $scheduled = self::createState();
        $scheduled->recordScheduledRestart($this->createTimer(), self::getDueAt());

        self::assertSame(2, $this->timers->countPendingTimers());

        $live->stop();
        $scheduled->stop();

        self::assertSame(0, $this->timers->countPendingTimers());
        self::assertNull($scheduled->restartDueAt);
        self::assertNotNull($live->handle, 'Shutdown still has to join the process.');
    }

    private function apply(WorkerSlotState $state, string $transition): void
    {
        match ($transition) {
            'beginSpawn' => $state->beginSpawn(),
            'markSpawnFailed' => $state->markSpawnFailed(),
            'recordSpawnedHandle' => $state->recordSpawnedHandle(self::createHandle(), null),
            'cancelReadyDeadline' => $state->cancelReadyDeadline(),
            'markGone' => $state->markGone(),
            'recordScheduledRestart' => $state->recordScheduledRestart($this->createTimer(), self::getDueAt()),
            'markQuarantined' => $state->markQuarantined(),
            'stop' => $state->stop(),
            default => self::fail('Unknown transition ' . $transition),
        };
    }

    private function createTimer(): TimerHandle
    {
        return $this->timers->startTimer(Duration::seconds(1), static function (): void {});
    }

    private function createStateInPhase(WorkerPhase $phase): WorkerSlotState
    {
        $state = self::createState();
        $path = match ($phase) {
            WorkerPhase::Stopped => [],
            WorkerPhase::Spawning => ['beginSpawn'],
            WorkerPhase::Live => ['beginSpawn', 'recordSpawnedHandle'],
            WorkerPhase::RestartScheduled => ['recordScheduledRestart'],
            WorkerPhase::Quarantined => ['markQuarantined'],
        };

        foreach ($path as $transition) {
            $this->apply($state, $transition);
        }

        return $state;
    }

    private static function createState(): WorkerSlotState
    {
        return new WorkerSlotState(new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new RecordingPoolListener());
    }

    private static function createHandle(): WorkerHandle
    {
        return new WorkerHandle(
            id: new WorkerId(0),
            process: new FakeWorkerProcess(),
            slot: new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(10, 256),
        );
    }

    private static function getDueAt(): Instant
    {
        return Instant::fromEpochMicroseconds(1_700_000_000_000_000);
    }
}
