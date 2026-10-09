<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\Exposure\ExposureCommandRouter;
use Stewart\Runtime\Broker\Exposure\PendingExposureCommands;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Protocol\Status\ExposedCommandStats;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Model\ExposedCommandOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\ExposureLinkFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(ExposureCommandRouter::class)]
#[CoversClass(PendingExposureCommands::class)]
final class ExposureCommandRouterTest extends TestCase
{
    private const string COMMAND_ID = '3f2b9c0e8d7a4f61';

    private FakeHaSession $session;

    private VirtualClock $clock;

    private WorkerSlotRegistry $slots;

    private WorkerHandle $worker;

    private AppMetrics $metrics;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $this->clock = new VirtualClock();
        $this->slots = new WorkerSlotRegistry();
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]));
        $this->metrics = new AppMetrics(WorkerSlotCollection::fromWorkerSlots([$slot]), $this->clock);
        $this->worker = new WorkerHandle(new WorkerId(0), new FakeWorkerProcess(), $slot, new NullLogger(), new OutboxLimits(100, 256));
        $state = $this->slots->findOrCreateSlotState($slot, new RecordingPoolListener());
        $state->beginSpawn();
        $state->recordSpawnedHandle($this->worker, null);
    }

    public function testCommandIsSentToOwningWorker(): void
    {
        $this->createRouter()->routeCommand(self::createCommand());

        $sent = $this->listCommandsSentToWorker();
        self::assertCount(1, $sent);
        self::assertSame(self::COMMAND_ID, $sent[0]->commandId);
        self::assertSame('demo', $sent[0]->scope->appId?->value);
        self::assertSame([], $this->session->commandAnswers);
    }

    public function testCommandOfOrphanIsRefused(): void
    {
        $this->createRouter()->routeCommand(self::createCommand(null));

        self::assertSame('App demo is not running.', $this->session->commandAnswers[0]->rejection ?? null);
    }

    public function testOutcomesAreCounted(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());
        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, true));
        $router->routeCommand(self::createCommand(new WorkerId(3)));

        $stats = $this->metrics->findAppRunningTotals(new AppId('demo'))?->buildAppStatus(
            new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime($this->clock)),
            ExposureLinkFixture::createWithoutExposures(),
        )->exposedCommands ?? [];

        self::assertSame(
            [[ExposedCommandOutcome::Accepted, 1], [ExposedCommandOutcome::Refused, 1]],
            array_map(static fn(ExposedCommandStats $stat): array => [$stat->outcome, $stat->count], $stats),
        );
    }

    public function testPausedAppIsRefused(): void
    {
        $this->createRouter(pausedApp: true)->routeCommand(self::createCommand());

        self::assertSame('App demo is paused.', $this->session->commandAnswers[0]->rejection ?? null);
        self::assertSame([], $this->listCommandsSentToWorker());
    }

    public function testCommandOfGoneWorkerIsRefused(): void
    {
        $this->createRouter()->routeCommand(self::createCommand(new WorkerId(3)));

        self::assertSame('App demo is not running.', $this->session->commandAnswers[0]->rejection ?? null);
    }

    public function testAcceptedAnswerReachesSession(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());

        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, true));

        self::assertTrue($this->session->commandAnswers[0]->isAccepted());
    }

    public function testRejectedAnswerKeepsItsReason(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());

        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, false, 'Alarm is armed.'));

        self::assertSame('Alarm is armed.', $this->session->commandAnswers[0]->rejection);
    }

    public function testSecondAnswerIsIgnored(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());

        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, true));
        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, false, 'Late.'));

        self::assertCount(1, $this->session->commandAnswers);
    }

    public function testWorkerLossRejectsItsCommands(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());

        $router->failCommandsOf(new WorkerId(0));
        $router->completeCommand($this->worker, new ExposedCommandAnswered(self::COMMAND_ID, true));

        self::assertCount(1, $this->session->commandAnswers);
        self::assertSame('App demo stopped while handling the command.', $this->session->commandAnswers[0]->rejection);
    }

    public function testExpiredCommandIsForgotten(): void
    {
        $router = $this->createRouter();
        $router->routeCommand(self::createCommand());
        $this->clock->moveTo($this->clock->getMonotonicTime()->plus(Duration::seconds(11)));
        $router->routeCommand(new ExposedEntityCommand('a91e44d0c2b35e78', new WorkerId(0), new AppId('demo'), new ExposedEntityKey('heater'), self::createSwitchCommand()));

        $router->failCommandsOf(new WorkerId(0));

        self::assertCount(1, $this->session->commandAnswers);
        self::assertSame('a91e44d0c2b35e78', $this->session->commandAnswers[0]->command->commandId);
    }

    private function createRouter(bool $pausedApp = false): ExposureCommandRouter
    {
        $pauses = new AppPauseRegistry(
            AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class, startsPaused: $pausedApp)]),
            new DaemonStartTime($this->clock),
        );
        $pending = new PendingExposureCommands($this->clock, new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));

        return new ExposureCommandRouter($this->session, $this->slots, $pauses, $pending, $this->metrics);
    }

    private static function createCommand(?WorkerId $owner = new WorkerId(0)): ExposedEntityCommand
    {
        return new ExposedEntityCommand(self::COMMAND_ID, $owner, new AppId('demo'), new ExposedEntityKey('night_mode'), self::createSwitchCommand());
    }

    private static function createSwitchCommand(): SwitchCommand
    {
        return new SwitchCommand(SwitchAction::TurnOn, new EventContext('context-1'));
    }

    /** @return list<ExposedEntityCommanded> */
    private function listCommandsSentToWorker(): array
    {
        EventLoopTicks::settle();
        $transport = $this->worker->getTransport();
        self::assertInstanceOf(FakeWorkerTransport::class, $transport);

        return $transport->listSentOfType(ExposedEntityCommanded::class);
    }
}
