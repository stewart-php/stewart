<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedServiceCall;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\OwnChangeReporter;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(WorkerHaContext::class)]
final class OwnChangeRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testOwnEchoAndManualChangeAreToldApart(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $broker = BrokerKernelFixture::boot(
            $session,
            WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger),
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('own-change-reporter'), OwnChangeReporter::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('own-change-reporter')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));
        EventLoopTicks::settleUntil(static fn(): bool => \in_array('Worker ready', $logger->listMessagesAt('info'), true));

        self::pushLightChange($session, 'on', new EventContext(FakeHaSession::CALL_CONTEXT_PREFIX . '1', userId: FakeHaSession::HA_USER_ID));
        EventLoopTicks::settleUntil(static fn(): bool => \count($session->receivedCalls) === 2);
        self::pushLightChange($session, 'off', new EventContext('manual', userId: 'resident'));
        EventLoopTicks::settleUntil(static fn(): bool => \count($session->receivedCalls) === 3);

        self::assertSame(
            [['by_last_call' => true, 'by_stewart' => true], ['by_last_call' => false, 'by_stewart' => false]],
            array_map(static fn(ReceivedServiceCall $call): array => $call->data, \array_slice($session->receivedCalls, 1)),
        );

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    private static function pushLightChange(FakeHaSession $session, string $state, EventContext $context): void
    {
        $light = new EntityId(OwnChangeReporter::LIGHT);

        $session->listener?->stateChanged(new StateChange($light, null, new EntityState($light, $state, context: $context), context: $context));
    }
}
