<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadRequestOutcome;
use Stewart\Runtime\Broker\Reload\ReloadResult;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\ReloadCoordinatorFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ReloadCoordinator::class)]
#[CoversClass(ReloadState::class)]
#[CoversClass(ReloadResult::class)]
#[CoversClass(ReloadRequestOutcome::class)]
final class ReloadCoordinatorTest extends TestCase
{
    private FakeWorkerSpawner $spawner;

    private WorkerPoolFixture $pools;

    private ScriptedCommandRunner $commands;

    private ReloadState $state;

    private RecordingLogger $logger;

    private ReloadCoordinator $reloads;

    protected function setUp(): void
    {
        $this->spawner = new FakeWorkerSpawner();
        $this->logger = new RecordingLogger();
        $this->pools = WorkerPoolFixture::createWorkerPool($this->spawner, timers: new ManualTimers(), logger: $this->logger);
        $this->commands = new ScriptedCommandRunner();
        $this->state = new ReloadState();
        $this->reloads = ReloadCoordinatorFixture::createReloadCoordinator($this->pools, commands: $this->commands, state: $this->state, logger: $this->logger);
        $this->pools->pool->startWorker(new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new RecordingPoolListener());
    }

    public function testReloadIsRefusedBeforeTheAppsRun(): void
    {
        self::assertSame(ReloadRequestOutcome::NotAccepting, $this->reloads->requestReload(ReloadTrigger::Control));
        self::assertSame([], $this->commands->commands);
    }

    public function testReloadChecksThenReplacesEveryWorker(): void
    {
        $this->reloads->startAccepting();

        self::assertSame(ReloadRequestOutcome::Started, $this->reloads->requestReload(ReloadTrigger::Control));
        $last = $this->awaitResult();

        self::assertSame(ReloadOutcome::Applied, $last->outcome);
        self::assertCount(1, $this->commands->commands);
        self::assertSame(2, $this->spawner->countSpawnedProcesses());
        self::assertSame(0, $this->pools->slots->findSlotState(new WorkerId(0))?->restarts);
    }

    public function testFailedCheckLeavesTheWorkersAlone(): void
    {
        $this->commands->queueResult(ReloadCoordinatorFixture::CONSOLE_SCRIPT, 1, '', "apps/Porch.php failed to load: syntax error\n");
        $this->reloads->startAccepting();

        $this->reloads->requestReload(ReloadTrigger::Signal);
        $last = $this->awaitResult();

        self::assertSame(ReloadOutcome::Failed, $last->outcome);
        self::assertSame('apps/Porch.php failed to load: syntax error', $last->error);
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
        self::assertSame(['Reload failed; the workers keep running as they were'], $this->logger->listMessagesAt('error'));
    }

    public function testRequestWhileAReloadRunsIsNotRepeated(): void
    {
        $this->commands->hold = new Latch();
        $this->reloads->startAccepting();

        self::assertSame(ReloadRequestOutcome::Started, $this->reloads->requestReload(ReloadTrigger::Watch));
        self::assertSame(ReloadRequestOutcome::AlreadyUnderway, $this->reloads->requestReload(ReloadTrigger::Http));

        $this->commands->hold->open();
        $this->awaitResult();

        self::assertSame(1, $this->state->countResults(ReloadOutcome::Applied));
        self::assertCount(1, $this->commands->commands);
    }

    public function testStoppingCancelsARunningCheck(): void
    {
        $this->commands->hold = new Latch();
        $this->reloads->startAccepting();
        $this->reloads->requestReload(ReloadTrigger::Control);
        EventLoopTicks::settle();

        $this->reloads->stopAccepting();
        EventLoopTicks::settleUntil(fn(): bool => !$this->state->isUnderway());

        self::assertTrue($this->commands->cancelled);
        self::assertNull($this->state->findLastResult());
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
        self::assertSame(ReloadRequestOutcome::NotAccepting, $this->reloads->requestReload(ReloadTrigger::Control));
    }

    private function awaitResult(): ReloadResult
    {
        EventLoopTicks::settleUntil(fn(): bool => $this->state->findLastResult() !== null);

        return $this->state->findLastResult() ?? self::fail('The reload never finished.');
    }
}
