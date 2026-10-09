<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\Future;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversNothing;
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
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\DyingSwitch;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversNothing]
final class ExposedCommandProcessTest extends TestCase
{
    private const float READY_SECONDS = 15;

    private const float ANSWER_SECONDS = 5;

    public function testWorkerDeathRejectsCommandAtOnce(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()), logger: $logger);
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('dying-switch'), DyingSwitch::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('dying-switch')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $exposed = $session->waitForNextExposedUpdate();
        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));
        $exposed->await(new TimeoutCancellation(self::READY_SECONDS));

        $answered = $session->waitForNextCommandAnswer();
        $session->pushExposedEntityCommand(new ExposedEntityCommand(
            '3f2b9c0e8d7a4f61',
            new WorkerId(0),
            new AppId('dying-switch'),
            new ExposedEntityKey('fuse'),
            new SwitchCommand(SwitchAction::TurnOn, new EventContext('context-1')),
        ));

        self::assertSame('App dying-switch stopped while handling the command.', $answered->await(new TimeoutCancellation(self::ANSWER_SECONDS))->rejection);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::READY_SECONDS));
    }
}
