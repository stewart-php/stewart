<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\Exposure\ExposureCommandRouter;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\AnsweredExposedCommand;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\HeaterSwitch;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\Exposure\ExposedCommandSettlements;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversClass(ExposureCommandRouter::class)]
#[CoversClass(ExposedCommandSettlements::class)]
final class ExposedCommandRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    private FakeHaSession $session;

    private BootedBroker $broker;

    /** @var Future<null> */
    private Future $running;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger);
        $this->broker = BrokerKernelFixture::boot(
            $this->session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('heater'), HeaterSwitch::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('heater')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $exposed = $this->session->waitForNextExposedUpdate();
        /** @var Future<null> $running */
        $running = async($this->broker->lifecycle->run(...));
        $this->running = $running;
        $exposed->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    protected function tearDown(): void
    {
        $this->broker->run->stop('test done');
        $this->running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testAcceptedCommandReachesHomeAssistant(): void
    {
        self::assertTrue($this->sendCommand(SwitchAction::TurnOn)->isAccepted());
    }

    public function testRejectedCommandKeepsAppReason(): void
    {
        self::assertSame(HeaterSwitch::REJECTION, $this->sendCommand(SwitchAction::TurnOff)->rejection);
    }

    private function sendCommand(SwitchAction $action): AnsweredExposedCommand
    {
        $answered = $this->session->waitForNextCommandAnswer();
        $this->session->pushExposedEntityCommand(new ExposedEntityCommand(
            '3f2b9c0e8d7a4f61',
            new WorkerId(0),
            new AppId('heater'),
            new ExposedEntityKey('heater'),
            new SwitchCommand($action, new EventContext('context-1')),
        ));

        return $answered->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }
}
