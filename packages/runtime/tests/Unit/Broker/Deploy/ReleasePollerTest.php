<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Deploy;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Broker\Deploy\ReleasePoller;
use Stewart\Runtime\Broker\Deploy\RunningReleaseDetector;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\BrokerStopOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ReleaseDirectories;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(ReleasePoller::class)]
#[CoversClass(DeployState::class)]
#[CoversClass(RunningReleaseDetector::class)]
final class ReleasePollerTest extends TestCase
{
    private const string RUNNING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string NEWER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string POLL = '1m';

    private ManualTimers $timers;

    private ScriptedCommandRunner $commands;

    private RecordingLogger $logger;

    private ReleaseDirectories $releases;

    private DeployState $state;

    private ?BootedBroker $broker = null;

    /** @var Future<mixed>|null */
    private ?Future $running = null;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->commands = new ScriptedCommandRunner();
        $this->logger = new RecordingLogger();
        $this->releases = ReleaseDirectories::createRunning(self::RUNNING);
        $this->state = new DeployState();
    }

    protected function tearDown(): void
    {
        $this->broker?->run->stop('test over');
        $this->running?->await();
        $this->releases->remove();
    }

    public function testUnchangedRefDeploysNothing(): void
    {
        $this->commands->queueResult('release-prepare', 0, self::RUNNING . " current\n");
        $this->startBroker();

        $this->advanceOnePoll();

        self::assertSame(['release-prepare'], $this->commands->listSubcommands());
        self::assertTrue($this->broker?->run->isRunning());
        self::assertNotNull($this->state->findLastPolledAt());
        self::assertSame(self::RUNNING, $this->state->findRunningCommit()?->value);
    }

    public function testCheckedCommitIsStagedAndRestartsBroker(): void
    {
        $this->commands->queueResult('release-prepare', 0, self::NEWER . " prepared\n");
        $this->startBroker();

        $this->advanceOnePoll();

        self::assertSame(['release-prepare', 'release-check', 'release-stage'], $this->commands->listSubcommands());
        self::assertSame(['stewart-entrypoint', 'release-stage', self::NEWER], $this->commands->commands[2]);
        self::assertSame(BrokerStopOutcome::RestartRequested, $this->awaitRunOutcome());
        self::assertContains('Deploying a new commit', $this->logger->listMessagesAt('info'));
    }

    public function testFailedCheckKeepsRunningRelease(): void
    {
        $this->commands->queueResult('release-prepare', 0, self::NEWER . " prepared\n");
        $this->commands->queueResult('release-check', 1, '', "/app/releases/b/apps/Porch.php failed to load: syntax error\nstewart-entrypoint: rejected b\n");
        $this->startBroker();

        $this->advanceOnePoll();

        self::assertSame(['release-prepare', 'release-check'], $this->commands->listSubcommands());
        self::assertTrue($this->broker?->run->isRunning());
        self::assertSame(self::NEWER, $this->state->findLastFailure()?->commit->value);
        self::assertSame('/app/releases/b/apps/Porch.php failed to load: syntax error', $this->state->findLastFailure()->reason);
        self::assertSame(1, $this->state->countFailures());
        self::assertContains('A new commit failed stewart check; the running release stays', $this->logger->listMessagesAt('error'));
    }

    public function testRejectedCommitIsNotCheckedAgain(): void
    {
        $this->commands->queueResult('release-prepare', 0, self::NEWER . " rejected\n");
        $this->startBroker();

        $this->advanceOnePoll();

        self::assertSame(['release-prepare'], $this->commands->listSubcommands());
        self::assertTrue($this->broker?->run->isRunning());
    }

    public function testFailedPrepareIsRetriedAtNextPoll(): void
    {
        $this->commands->queueResult('release-prepare', 1, '', "stewart-entrypoint: fetch failed\n");
        $this->commands->queueResult('release-prepare', 0, self::RUNNING . " current\n");
        $this->startBroker();

        $this->advanceOnePoll();
        $this->advanceOnePoll();

        self::assertSame(['release-prepare', 'release-prepare'], $this->commands->listSubcommands());
        self::assertContains('Could not prepare the latest commit; trying again at the next poll', $this->logger->listMessagesAt('warning'));
        self::assertTrue($this->broker?->run->isRunning());
    }

    public function testPollIsSkippedWhileOneRuns(): void
    {
        $this->commands->hold = new Latch();
        $this->startBroker();

        $this->advanceOnePoll();
        $this->advanceOnePoll();

        self::assertSame(['release-prepare'], $this->commands->listSubcommands());
    }

    public function testStopCancelsTheRunningCommand(): void
    {
        $this->commands->hold = new Latch();
        $this->startBroker();
        $this->advanceOnePoll();

        $this->broker?->run->stop('signal');

        self::assertSame(BrokerStopOutcome::Stopped, $this->awaitRunOutcome());
        EventLoopTicks::settle();
        self::assertTrue($this->commands->cancelled);
    }

    public function testPollingOffRunsNoCommands(): void
    {
        $this->startBroker(poll: 'off');

        $this->advanceOnePoll();

        self::assertSame([], $this->commands->commands);
    }

    public function testProjectOutsideReleasesOnlyWarns(): void
    {
        $this->startBroker(projectRoot: new ProjectRoot(sys_get_temp_dir()));

        $this->advanceOnePoll();

        self::assertSame([], $this->commands->commands);
        self::assertSame(
            ['deploy.git.poll is set, but Stewart is not running from a release directory, so new commits are not deployed.'],
            $this->logger->listMessagesAt('warning'),
        );
    }

    private function startBroker(string $poll = self::POLL, ?ProjectRoot $projectRoot = null): void
    {
        $pools = WorkerPoolFixture::createWorkerPool(new FakeWorkerSpawner(), timers: $this->timers, logger: $this->logger, outboxLimits: new OutboxLimits(100, 256));
        $app = new AppDefinition(new AppId('demo'), Demo::class);
        $slots = WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([$app]))]);
        $overrides = new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(ExternalCommandRunner::class, $this->commands)
            ->withService(DeployState::class, $this->state)
            ->withService(ProjectRoot::class, $projectRoot ?? $this->releases->getProjectRoot(self::RUNNING));

        $this->broker = BrokerKernelFixture::boot(
            new FakeHaSession(),
            $pools,
            $slots,
            AppIdCollection::fromIds([new AppId('demo')]),
            $this->logger,
            ['shutdown_grace' => '10ms', 'deploy' => ['git' => ['poll' => $poll]]],
            $overrides,
        );
        $this->running = async($this->broker->lifecycle->run(...));
        EventLoopTicks::settle();
    }

    private function advanceOnePoll(): void
    {
        $this->timers->delay(Duration::parse(self::POLL));
        EventLoopTicks::settle();
    }

    private function awaitRunOutcome(): BrokerStopOutcome
    {
        $outcome = $this->running?->await();
        $this->running = null;
        self::assertInstanceOf(BrokerStopOutcome::class, $outcome);

        return $outcome;
    }
}
